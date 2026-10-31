<?php

namespace PryTax\DataLifecycle\Backup\Http\Controllers;

use Core\Http\Controllers\CrudController;
use Core\Http\Requests\Crud\DestroyRequest;
use Core\Http\Requests\Crud\IndexRequest;
use Core\Http\Requests\Crud\ShowRequest;
use Core\Http\Responses\ApiResponse;
use Core\Models\AuditLog;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PryTax\DataLifecycle\Backup\ArchiveImports;
use PryTax\DataLifecycle\Backup\Enums\BackupStatus;
use PryTax\DataLifecycle\Backup\Http\Controllers\Traits\LimitsDownloads;
use PryTax\DataLifecycle\Backup\Http\Requests\DownloadBackupRequest;
use PryTax\DataLifecycle\Backup\Http\Requests\ImportBackupRequest;
use PryTax\DataLifecycle\Backup\Http\Resources\BackupCollection;
use PryTax\DataLifecycle\Backup\Http\Resources\BackupResource;
use PryTax\DataLifecycle\Backup\ImportException;
use PryTax\DataLifecycle\Backup\ImportResult;
use PryTax\DataLifecycle\Backup\Internal\ArchiveRepacker;
use PryTax\DataLifecycle\Backup\Internal\ArchiveStore;
use PryTax\DataLifecycle\Backup\Models\Backup;
use PryTax\DataLifecycle\Backup\Models\BackupDownload;
use PryTax\DataLifecycle\Backup\Policies\BackupPolicy;
use PryTax\DataLifecycle\Backup\Repositories\BackupRepository;

/**
 * Class BackupController.
 *
 * Backups of the organization's profiles (backups.md §4.7): `organization/backups`, filtered by `profile_id`,
 * `tier` and `status`; a download link of an archive (10 minutes, saved under its name; with a password, of an
 * archive packed again with AES-256), each logged with the user, the IP address and the browser, and in the audit
 * log; deleting a manual backup.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Backup\Http\Controllers
 */
class BackupController extends CrudController
{
    use LimitsDownloads;

    /**
     * Resource, which wraps a model.
     *
     * @var string
     */
    protected string $resourceClass = BackupResource::class;

    /**
     * Collection, which wraps a list of models.
     *
     * @var string
     */
    protected string $collectionClass = BackupCollection::class;

    /**
     * Operations, which the controller handles, mapped to the classes of their requests.
     *
     * @var array
     */
    protected array $operations = [
        'index' => IndexRequest::class,
        'show' => ShowRequest::class,
        'download' => DownloadBackupRequest::class,
        'destroy' => DestroyRequest::class,
        'import' => ImportBackupRequest::class,
    ];

    /**
     * BackupController constructor.
     *
     * @param BackupPolicy $policy
     * @param BackupRepository $repository
     * @param ArchiveStore $store
     * @param ArchiveRepacker $repacker
     * @param ArchiveImports $imports
     */
    public function __construct(
        BackupPolicy $policy,
        BackupRepository $repository,
        protected ArchiveStore $store,
        protected ArchiveRepacker $repacker,
        protected ArchiveImports $imports
    ) {
        parent::__construct($policy, $repository);
    }

    /**
     * Register the routes, inside a group of the organization's routes.
     *
     * @return void
     */
    public static function routes(): void
    {
        Route::get('backups', [static::class, 'index'])->name('backups.index');
        Route::post('backups/import', [static::class, 'import'])->middleware('throttle:30,60,backups-import')->name('backups.import');
        Route::get('backups/{id}', [static::class, 'show'])->name('backups.show');
        Route::post('backups/{id}/download', [static::class, 'download'])->name('backups.download');
        Route::delete('backups/{id}', [static::class, 'destroy'])->name('backups.destroy');
    }

    /**
     * Handle the `download` operation: a signed URL of the archive, logged.
     *
     * @param DownloadBackupRequest $request
     *
     * @return ApiResponse
     */
    protected function handleDownload(DownloadBackupRequest $request): ApiResponse
    {
        /** @var Backup $backup */
        $backup = $this->builder($request)->findOrFail($request->getId());

        if ($refusal = $this->refuseDownload($request)) {
            return $refusal;
        }

        $expiresAt = now()->addMinutes((int) config('data-lifecycle.backups.url_lifetime'));
        $password = $request->validated('password');
        [$disk, $path] = [$backup->disk, $backup->object_path];

        if (filled($password)) {
            $local = $this->store->fetch($backup->disk, $backup->object_path);

            try {
                $encrypted = $this->repacker->encrypt($local, $password);
                [$disk, $path] = [$this->store->diskName(), 'tmp/downloads/' . Str::ulid() . '.zip'];
                $this->store->put($encrypted, $path);
                unlink($encrypted);
            } finally {
                unlink($local);
            }
        }

        BackupDownload::query()->create([
            'backup_id' => $backup->id,
            'user_id' => $request->user()?->getKey(),
            'kind' => 'profile',
            'ip' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
        ]);
        // An entry of the organization: its subject's id would be a ULID, which audit_logs.subject_id can't hold
        AuditLog::record('backup_downloaded', null, [
            'name' => $backup->getMorphClass() . '.downloaded',
            'metadata' => [
                'backup_id' => $backup->id,
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'encrypted' => filled($password),
            ],
        ]);

        return ApiResponse::ok(['data' => [
            'url' => $this->store->url($disk, $path, (string) $backup->file_name, $expiresAt),
            'expires_at' => $expiresAt->toIso8601String(),
        ]]);
    }

    /**
     * Handle the `destroy` operation: delete a manual backup's archive, keeping its record as pruned.
     *
     * @param DestroyRequest $request
     *
     * @return ApiResponse
     */
    protected function handleDestroy(DestroyRequest $request): ApiResponse
    {
        /** @var Backup $backup */
        $backup = $this->builder($request)->findOrFail($request->getId());

        $this->store->delete($backup->disk, $backup->object_path);
        $backup->forceFill(['status' => BackupStatus::Pruned, 'pruned_at' => now()])->save();

        return ApiResponse::ok()->setMessage('Deleted');
    }

    /**
     * Handle the `import` operation: restore the profiles of an archive, uploaded, as new profiles of the organization,
     * never in place (backups.md §5.1): a profile's archive, or a run's bundle of every profile, all of them or none;
     * a dry run imports, verifies and rolls back each, telling what the archive holds. A refused archive (another
     * app's, a period's export, a profile the organization has, a wrong password) is a 422 of `archive`.
     *
     * @param ImportBackupRequest $request
     *
     * @return ApiResponse
     * @throws ValidationException
     */
    protected function handleImport(ImportBackupRequest $request): ApiResponse
    {
        $dryRun = $request->boolean('dry_run');
        set_time_limit(300);

        try {
            $results = $this->imports->importAll(
                (string) $request->file('archive')->getRealPath(),
                $request->orgId(),
                $dryRun,
                $request->validated('password'),
            );
        } catch (ImportException $exception) {
            throw ValidationException::withMessages(['archive' => [__($exception->getMessage())]]);
        }

        if (!$dryRun) {
            foreach ($results as $result) {
                AuditLog::record('backup_imported', null, [
                    'name' => (new Backup)->getMorphClass() . '.imported',
                    'metadata' => ['tenant_id' => $result->tenantId, 'file_name' => $request->file('archive')->getClientOriginalName()],
                ]);
            }
        }

        return ApiResponse::ok(['data' => [
            'dry_run' => $dryRun,
            'tenants' => array_map(fn (ImportResult $result) => [
                'tenant_id' => $result->tenantId,
                'tenant' => $result->tenant,
                'rows' => array_sum($result->rows),
                'documents' => $result->documents,
                'skipped_files' => $result->skippedFiles,
            ], $results),
        ]]);
    }
}
