<?php

namespace PryTax\Http\Controllers;

use Core\Http\Controllers\Auth\AuthController;
use Core\Http\Responses\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Native\Desktop\Dialog;
use Native\Desktop\Facades\App;
use Native\Desktop\Facades\QueueWorker;
use PryTax\Helpers\Desktop;
use PryTax\Services\Desktop\DatabaseRestore;
use PryTax\Services\Setup\InstallationSetup;

/**
 * Class DesktopRestoreController.
 *
 * Restoring the desktop app on a new computer (builds.md §4.6), from its first screen: the
 * recovery kit and the old computer's database, each chosen in a native dialog, checked together, and installed in
 * place of the new, empty installation, which then relaunches. Only in the desktop app, and only before anyone
 * registered on it.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Controllers
 */
class DesktopRestoreController extends Controller
{
    /**
     * DesktopRestoreController constructor.
     *
     * @param DatabaseRestore $restore
     * @param InstallationSetup $setup
     */
    public function __construct(protected DatabaseRestore $restore, protected InstallationSetup $setup) {}

    /**
     * Register the routes of the controller, limited as api-core's sign-in.
     *
     * @return void
     */
    public static function routes(): void
    {
        Route::middleware('throttle:' . AuthController::RATE_LIMITER)->prefix('desktop/restore')->name('desktop.restore.')->group(function () {
            Route::post('kit', [static::class, 'chooseKit'])->name('kit');
            Route::post('database', [static::class, 'chooseDatabase'])->name('database');
            Route::post('/', [static::class, 'restore'])->name('restore');
        });
    }

    /**
     * Choose the recovery kit in a native dialog; nothing, when it's cancelled.
     *
     * @return ApiResponse
     */
    public function chooseKit(): ApiResponse
    {
        $this->authorizeRestore();

        $path = Dialog::new()->title(__('Choose the recovery kit'))->filter(__('Text'), ['txt'])->open();

        if (is_string($path) && $path !== '') {
            $this->restore->keyOf($path);
        }

        return ApiResponse::ok(['data' => $this->choice($path)]);
    }

    /**
     * Choose the old computer's database file in a native dialog; nothing, when it's cancelled.
     *
     * @return ApiResponse
     */
    public function chooseDatabase(): ApiResponse
    {
        $this->authorizeRestore();

        $path = Dialog::new()->title(__('Choose the database file'))->filter(__('Database'), ['sqlite', 'db'])->open();

        return ApiResponse::ok(['data' => $this->choice($path)]);
    }

    /**
     * Check the kit and the database together, install them and relaunch the app.
     *
     * @param Request $request
     *
     * @return ApiResponse
     */
    public function restore(Request $request): ApiResponse
    {
        $this->authorizeRestore();

        $request->validate(['kit' => ['required', 'string'], 'database' => ['required', 'string']]);

        $key = $this->restore->keyOf($request->string('kit')->value());
        $this->restore->verify($request->string('database')->value(), $key);

        // Nothing may hold the new installation's database (NativePHP's connection) while it's replaced
        QueueWorker::down('default');
        DB::disconnect('nativephp');

        $this->restore->install($request->string('database')->value(), $key);
        App::relaunch();

        return ApiResponse::ok(['data' => ['restored' => true]]);
    }

    /**
     * Get a chosen file's path and name.
     *
     * @param mixed $path
     *
     * @return array{path: string|null, name: string|null}
     */
    protected function choice(mixed $path): array
    {
        $path = is_string($path) && $path !== '' ? $path : null;

        return ['path' => $path, 'name' => $path === null ? null : basename($path)];
    }

    /**
     * Let only the desktop app restore, and only before anyone registered on it.
     *
     * @return void
     */
    protected function authorizeRestore(): void
    {
        abort_unless(Desktop::isRunning(), 404);
        abort_unless($this->setup->isNeeded(), 409, __('PryTax is set up already.'));
    }
}
