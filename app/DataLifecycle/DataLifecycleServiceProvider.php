<?php

namespace PryTax\DataLifecycle;

use Illuminate\Support\ServiceProvider;
use PryTax\DataLifecycle\Console\BackupImportCommand;
use PryTax\DataLifecycle\Console\BackupSnapshotCommand;

/**
 * Class DataLifecycleServiceProvider.
 *
 * The backups and retention module (backups.md §10): its config, migrations, views, commands and schedule. The
 * engine knows nothing of the app; the app binds the module's contracts to its definitions.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle
 */
class DataLifecycleServiceProvider extends ServiceProvider
{
    /**
     * Register the module's config.
     *
     * @return void
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/config/data-lifecycle.php', 'data-lifecycle');
    }

    /**
     * Bootstrap the module's migrations and views.
     *
     * @return void
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/database/migrations');
        $this->loadViewsFrom(__DIR__ . '/resources/views', 'data-lifecycle');

        if ($this->app->runningInConsole()) {
            $this->commands([
                BackupImportCommand::class,
                BackupSnapshotCommand::class,
            ]);
        }
    }
}
