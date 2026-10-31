<?php

namespace PryTax\Http\Controllers;

use Core\Http\Responses\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Native\Desktop\Dialog;
use Native\Desktop\Facades\App;
use Native\Desktop\Facades\QueueWorker;
use PryTax\Helpers\Desktop;
use PryTax\Services\Installation\InstallationArchive;
use PryTax\Services\Installation\InstallationBackup;
use PryTax\Services\Installation\InstallationComparison;
use PryTax\Services\Installation\InstallationRestore;
use PryTax\Services\Installation\InstallationWork;
use PryTax\Services\Setup\InstallationSetup;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * Class InstallationBackupController.
 *
 * Full backups of the installation, and restoring one in its place (builds.md §4.11), where `prytax.full_backups`
 * allows it (the desktop app; a web app on this computer) and PryTax runs on a SQLite file: for owners, or for anyone
 * before the first user registered (a new computer's first launch).
 * - Saving a backup: into a file chosen in a native dialog in the desktop app, else through a signed download link.
 * - Restoring: the backup chosen in a native dialog, or uploaded in pieces (PHP takes small uploads), is opened with
 *   its password and compared with this installation; this installation's own state must be saved first (unless it
 *   has nobody yet), then the backup takes its place, the queue worker stopped, everyone signed out, and the desktop
 *   app relaunched.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Controllers
 */
class InstallationBackupController extends Controller
{
    /**
     * The bytes of an uploaded piece at most.
     *
     * @var int
     */
    public const int PIECE = 1024 * 1024;

    /**
     * InstallationBackupController constructor.
     *
     * @param InstallationBackup $backup
     * @param InstallationComparison $comparison
     * @param InstallationRestore $restore
     * @param InstallationSetup $setup
     */
    public function __construct(
        protected InstallationBackup $backup,
        protected InstallationComparison $comparison,
        protected InstallationRestore $restore,
        protected InstallationSetup $setup,
    ) {}

    /**
     * Register the routes of the controller.
     *
     * @return void
     */
    public static function routes(): void
    {
        Route::prefix('installation')->name('installation.')->middleware('throttle:600,1,installation')->group(function () {
            Route::get('/', [static::class, 'status'])->name('status');
            Route::post('backups', [static::class, 'backup'])->name('backup');
            Route::get('backups/{file}', [static::class, 'download'])->middleware('signed')->where('file', '[A-Za-z0-9]+')->name('download');
            Route::post('uploads', [static::class, 'startUpload'])->name('uploads.start');
            Route::post('uploads/{upload}', [static::class, 'appendUpload'])->where('upload', '[A-Za-z0-9]+')->name('uploads.append');
            Route::post('restores/choose', [static::class, 'choose'])->name('restores.choose');
            Route::post('restores', [static::class, 'compare'])->name('restores.compare');
            Route::post('restores/{token}', [static::class, 'restore'])->where('token', '[A-Za-z0-9]+')->name('restores.restore');
            Route::delete('restores/{token}', [static::class, 'cancel'])->where('token', '[A-Za-z0-9]+')->name('restores.cancel');
        });
    }

    /**
     * Tell whether full backups are offered here, and to whom.
     *
     * @return ApiResponse
     */
    public function status(): ApiResponse
    {
        $enabled = (bool) config('prytax.full_backups');

        return ApiResponse::ok(['data' => [
            'enabled' => $enabled,
            'supported' => $enabled && InstallationBackup::isSupported(),
            'allowed' => $enabled && $this->isAllowed(),
            'desktop' => Desktop::isRunning(),
            'first_launch' => $this->setup->isNeeded(),
        ]]);
    }

    /**
     * Back up the installation: into a file chosen in the desktop app's dialog, or for a download link. A restore's
     * token marks its installation's state as saved.
     *
     * @param Request $request
     *
     * @return ApiResponse
     */
    public function backup(Request $request): ApiResponse
    {
        $this->authorizeFull();
        $request->validate([
            'password' => ['required', 'string', 'min:8', 'max:128', 'confirmed'],
            'restore' => ['nullable', 'string', 'max:64'],
        ]);
        set_time_limit(600);

        $path = $this->backup->write($request->string('password')->value());
        $restore = $request->string('restore')->value() ?: null;

        if (!Desktop::isRunning()) {
            return ApiResponse::ok(['data' => [
                'saved' => null,
                'download_url' => URL::temporarySignedRoute('installation.download', now()->addMinutes(30), array_filter([
                    'file' => pathinfo($path, PATHINFO_FILENAME),
                    'restore' => $restore,
                ])),
            ]]);
        }

        $target = Dialog::new()
            ->title(__('Save the full backup'))
            ->defaultPath(rtrim((string) config('filesystems.disks.documents.root'), '/\\') . DIRECTORY_SEPARATOR . InstallationBackup::fileName())
            ->filter(__('Backup'), ['zip'])
            ->save();

        if (!is_string($target) || $target === '') {
            unlink($path);

            return ApiResponse::ok(['data' => ['saved' => null, 'download_url' => null]]);
        }

        // Another disk, maybe, so copied
        copy($path, $target);
        unlink($path);
        $this->markSaved($restore);

        return ApiResponse::ok(['data' => ['saved' => basename($target), 'download_url' => null]]);
    }

    /**
     * Download a backup made for a link, which is removed after it, marking a restore's installation as saved.
     *
     * @param Request $request
     * @param string $file
     *
     * @return BinaryFileResponse
     */
    public function download(Request $request, string $file): BinaryFileResponse
    {
        $this->authorizeFull();

        $path = InstallationWork::path("backups/$file.zip");
        abort_unless(is_file($path), 404);
        $this->markSaved($request->query('restore'));

        return response()->download($path, InstallationBackup::fileName(), ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }

    /**
     * Start an upload of a backup, which comes in pieces.
     *
     * @return ApiResponse
     */
    public function startUpload(): ApiResponse
    {
        $this->authorizeFull();

        $id = Str::random(32);
        touch(InstallationWork::directory('uploads') . "/$id.zip");

        return ApiResponse::ok(['data' => ['id' => $id, 'piece' => self::PIECE]]);
    }

    /**
     * Append a piece of an upload, which must start where the upload ends.
     *
     * @param Request $request
     * @param string $upload
     *
     * @return ApiResponse
     */
    public function appendUpload(Request $request, string $upload): ApiResponse
    {
        $this->authorizeFull();
        $request->validate([
            'piece' => ['required', 'file', 'max:' . intdiv(self::PIECE, 1024)],
            'offset' => ['required', 'integer', 'min:0'],
        ]);

        $path = InstallationWork::path("uploads/$upload.zip");
        abort_unless(is_file($path), 404);
        clearstatcache(true, $path);

        if (filesize($path) !== $request->integer('offset')) {
            return ApiResponse::make(['data' => ['size' => filesize($path)]], 409, 'Conflict');
        }

        file_put_contents($path, (string) file_get_contents((string) $request->file('piece')->getRealPath()), FILE_APPEND);
        clearstatcache(true, $path);

        return ApiResponse::ok(['data' => ['size' => filesize($path)]]);
    }

    /**
     * Choose a backup's file in the desktop app's native dialog.
     *
     * @return ApiResponse
     */
    public function choose(): ApiResponse
    {
        $this->authorizeFull();
        abort_unless(Desktop::isRunning(), 404);

        $path = Dialog::new()->title(__('Choose the full backup'))->filter(__('Backup'), ['zip'])->open();
        $path = is_string($path) && $path !== '' ? $path : null;

        return ApiResponse::ok(['data' => ['path' => $path, 'name' => $path === null ? null : basename($path)]]);
    }

    /**
     * Open a backup (uploaded, or a file chosen in the desktop app) with its password, and compare it with this
     * installation; it's kept under a token for the restore.
     *
     * @param Request $request
     *
     * @return ApiResponse
     */
    public function compare(Request $request): ApiResponse
    {
        $this->authorizeFull();
        $request->validate([
            'password' => ['required', 'string', 'max:128'],
            'upload' => ['required_without:path', 'nullable', 'string', 'regex:/^[A-Za-z0-9]+$/'],
            'path' => ['required_without:upload', 'nullable', 'string'],
        ]);
        set_time_limit(600);

        $upload = $request->filled('upload') ? InstallationWork::path("uploads/{$request->string('upload')}.zip") : null;
        $source = $upload ?? (Desktop::isRunning() ? $request->string('path')->value() : '');

        if (!is_file($source)) {
            throw ValidationException::withMessages(['archive' => [__('Choose the full backup again.')]]);
        }

        $token = Str::random(40);
        $directory = InstallationWork::directory("restores/$token");

        try {
            $archive = InstallationArchive::extract($source, $request->string('password')->value(), $directory);
            $backup = InstallationComparison::open($archive->databasePath());
            $this->comparison->verify($backup, $archive->key);
            $current = InstallationComparison::open((string) DB::connection()->getConfig('database'));
            $comparison = $this->comparison->compare($backup, $archive->key, $current, (string) config('app.key'));
        } catch (Throwable $exception) {
            File::deleteDirectory($directory);

            throw $exception;
        } finally {
            if ($upload !== null && is_file($upload)) {
                unlink($upload);
            }
        }

        return ApiResponse::ok(['data' => [
            'token' => $token,
            'made_at' => $archive->manifest['made_at'] ?? null,
            'app_version' => $archive->manifest['app_version'] ?? null,
            'documents' => $archive->manifest['documents'] ?? null,
            'archives' => $archive->manifest['archives'] ?? null,
            'needs_current_backup' => !$this->setup->isNeeded(),
            ...$comparison,
        ]]);
    }

    /**
     * Restore an opened backup in place of this installation, once its state was saved (unless nobody registered
     * yet): the queue worker stopped, everyone signed out, and the desktop app relaunched after it.
     *
     * @param Request $request
     * @param string $token
     *
     * @return ApiResponse
     */
    public function restore(Request $request, string $token): ApiResponse
    {
        $this->authorizeFull();
        set_time_limit(600);

        $directory = InstallationWork::path("restores/$token");
        $archive = InstallationArchive::at($directory) ?? throw ValidationException::withMessages(['archive' => [__('Choose the full backup again.')]]);
        $schema = $this->comparison->schemaOf(
            InstallationComparison::open($archive->databasePath()),
            InstallationComparison::open((string) DB::connection()->getConfig('database')),
        );

        if ($schema === 'newer') {
            throw ValidationException::withMessages(['archive' => [__('The backup was made by a newer PryTax: update it here first.')]]);
        }

        if (!$this->setup->isNeeded() && !Cache::get($this->savedKey($token))) {
            throw ValidationException::withMessages(['current_backup' => [__('Save this computer\'s current state first.')]]);
        }

        // Nothing may hold the database while it's replaced
        if (Desktop::isRunning()) {
            QueueWorker::down('default');
        }

        // The restored users aren't this session's; the roles' guard is the sessions' again for syncing them
        Auth::guard('web')->logout();
        Auth::shouldUse('web');

        if ($request->hasSession()) {
            $request->session()->invalidate();
        }

        $this->restore->install($archive);

        if (config('session.driver') === 'file') {
            File::cleanDirectory((string) config('session.files'));
        }

        File::deleteDirectory($directory);
        Cache::forget($this->savedKey($token));

        if (Desktop::isRunning()) {
            App::relaunch();
        } else {
            // A web app's queue worker still holds the replaced database
            Artisan::call('queue:restart');
        }

        return ApiResponse::ok(['data' => ['restored' => true, 'relaunched' => Desktop::isRunning()]]);
    }

    /**
     * Drop an opened backup.
     *
     * @param string $token
     *
     * @return ApiResponse
     */
    public function cancel(string $token): ApiResponse
    {
        $this->authorizeFull();

        File::deleteDirectory(InstallationWork::path("restores/$token"));
        Cache::forget($this->savedKey($token));

        return ApiResponse::ok(['data' => ['cancelled' => true]]);
    }

    /**
     * Mark a restore's installation as saved, when its backup is still open.
     *
     * @param mixed $token
     *
     * @return void
     */
    protected function markSaved(mixed $token): void
    {
        if (is_string($token) && preg_match('/^[A-Za-z0-9]+$/', $token) && is_dir(InstallationWork::path("restores/$token"))) {
            Cache::put($this->savedKey($token), true, now()->addHours(InstallationWork::HOURS));
        }
    }

    /**
     * Get the cache key of a restore's saved state.
     *
     * @param string $token
     *
     * @return string
     */
    protected function savedKey(string $token): string
    {
        return "installation-restore:$token:saved";
    }

    /**
     * Determine whether this request may back up and restore the installation: anyone before the first user
     * registered, else an owner.
     *
     * @return bool
     */
    protected function isAllowed(): bool
    {
        return $this->setup->isNeeded() || (bool) Auth::guard('sanctum')->user()?->hasRole('owner');
    }

    /**
     * Let only where it's allowed and supported, and only those allowed.
     *
     * @return void
     */
    protected function authorizeFull(): void
    {
        abort_unless(config('prytax.full_backups') && InstallationBackup::isSupported(), 404);
        abort_unless($this->isAllowed(), 403);
    }
}
