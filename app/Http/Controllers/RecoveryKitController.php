<?php

namespace PryTax\Http\Controllers;

use Core\Http\Responses\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Native\Desktop\Dialog;
use PryTax\Helpers\Desktop;
use PryTax\Services\Desktop\RecoveryKit;

/**
 * Class RecoveryKitController.
 *
 * The installation's recovery kit, for its owners (builds.md §4.5, §1.4): its text, saving it through a native dialog
 * in the desktop app (a browser downloads it itself), and the owner's word that they kept it somewhere safe. Until then
 * the SPA asks for it before anything else. Only the desktop app and one person's installation have one (else 404).
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Controllers
 */
class RecoveryKitController extends Controller
{
    /**
     * RecoveryKitController constructor.
     *
     * @param RecoveryKit $kit
     */
    public function __construct(protected RecoveryKit $kit) {}

    /**
     * Register the routes of the controller.
     *
     * @return void
     */
    public static function routes(): void
    {
        Route::prefix('desktop/recovery-kit')->name('desktop.recovery-kit.')->group(function () {
            Route::get('/', [static::class, 'show'])->name('show');
            Route::post('save', [static::class, 'save'])->name('save');
            Route::post('confirm', [static::class, 'confirm'])->name('confirm');
        });
    }

    /**
     * Show the kit, and when the owner kept it.
     *
     * @param Request $request
     *
     * @return ApiResponse
     */
    public function show(Request $request): ApiResponse
    {
        $this->authorizeOwner($request);

        return ApiResponse::ok(['data' => $this->data()]);
    }

    /**
     * Save the kit where the owner chooses in a native dialog, in Documents by default; nothing, when they cancel.
     *
     * @param Request $request
     *
     * @return ApiResponse
     */
    public function save(Request $request): ApiResponse
    {
        $this->authorizeOwner($request);
        abort_unless(Desktop::isRunning(), 404);

        $path = Dialog::new()
            ->title(__('Save the recovery kit'))
            ->defaultPath(rtrim((string) config('filesystems.disks.documents.root'), '/\\') . DIRECTORY_SEPARATOR . $this->kit->filename())
            ->filter(__('Text'), ['txt'])
            ->save();

        if (!is_string($path) || $path === '') {
            return ApiResponse::ok(['data' => ['path' => null, ...$this->data()]]);
        }

        file_put_contents($path, $this->kit->text());
        $this->kit->markSaved('file');

        return ApiResponse::ok(['data' => ['path' => $path, ...$this->data()]]);
    }

    /**
     * Take the owner's word that they kept the kit somewhere safe: copied, or (`via` `file`) downloaded by a browser.
     *
     * @param Request $request
     *
     * @return ApiResponse
     */
    public function confirm(Request $request): ApiResponse
    {
        $this->authorizeOwner($request);
        $this->kit->markSaved($request->string('via')->value() === 'file' ? 'file' : 'copy');

        return ApiResponse::ok(['data' => $this->data()]);
    }

    /**
     * Get the kit's text, file name, when it was kept, and whether it holds a recovery code.
     *
     * @return array{text: string, filename: string, saved_at: string|null, recovery_code: bool}
     */
    protected function data(): array
    {
        return [
            'recovery_code' => $this->kit->hasCode(),
            'text' => $this->kit->text(),
            'filename' => $this->kit->filename(),
            'saved_at' => $this->kit->savedAt()?->toIso8601String(),
        ];
    }

    /**
     * Let only an owner reach the kit, where there's one.
     *
     * @param Request $request
     *
     * @return void
     */
    protected function authorizeOwner(Request $request): void
    {
        abort_unless($this->kit->exists(), 404);
        abort_unless((bool) $request->user()?->hasRole('owner'), 403);
    }
}
