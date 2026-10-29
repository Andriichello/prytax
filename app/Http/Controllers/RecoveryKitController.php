<?php

namespace PryTax\Http\Controllers;

use Core\Http\Responses\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Native\Desktop\Dialog;
use PryTax\Helpers\Desktop;
use PryTax\Services\Desktop\InstallationKey;
use PryTax\Services\Desktop\RecoveryKit;

/**
 * Class RecoveryKitController.
 *
 * The desktop installation's recovery kit, for its owners (APP-BUILDS-PLAN.md, phase 3, step 6): its text, saving it
 * through a native dialog, and the owner's word that they copied it somewhere safe. Until then the SPA asks for it
 * before anything else. Outside the desktop app it doesn't exist (404).
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
     * @param InstallationKey $key
     */
    public function __construct(protected RecoveryKit $kit, protected InstallationKey $key) {}

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

        $path = Dialog::new()
            ->title(__('Save the recovery kit'))
            ->defaultPath(rtrim((string) config('filesystems.disks.documents.root'), '/\\') . DIRECTORY_SEPARATOR . $this->kit->filename())
            ->filter(__('Text'), ['txt'])
            ->save();

        if (!is_string($path) || $path === '') {
            return ApiResponse::ok(['data' => ['path' => null, ...$this->data()]]);
        }

        file_put_contents($path, $this->kit->text());
        $this->key->markRecoveryKitSaved('file');

        return ApiResponse::ok(['data' => ['path' => $path, ...$this->data()]]);
    }

    /**
     * Take the owner's word that they copied the kit somewhere safe.
     *
     * @param Request $request
     *
     * @return ApiResponse
     */
    public function confirm(Request $request): ApiResponse
    {
        $this->authorizeOwner($request);
        $this->key->markRecoveryKitSaved('copy');

        return ApiResponse::ok(['data' => $this->data()]);
    }

    /**
     * Get the kit's text, file name and when it was kept.
     *
     * @return array{text: string, filename: string, saved_at: string|null}
     */
    protected function data(): array
    {
        return [
            'text' => $this->kit->text(),
            'filename' => $this->kit->filename(),
            'saved_at' => $this->key->recoveryKitSavedAt()?->toIso8601String(),
        ];
    }

    /**
     * Let only an owner in the desktop app reach the kit.
     *
     * @param Request $request
     *
     * @return void
     */
    protected function authorizeOwner(Request $request): void
    {
        abort_unless(Desktop::isRunning(), 404);
        abort_unless((bool) $request->user()?->hasRole('owner'), 403);
    }
}
