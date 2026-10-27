<?php

namespace PryTax\DataLifecycle;

use Core\Providers\MorphServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;
use PryTax\DataLifecycle\Backup\Models\Backup;
use PryTax\DataLifecycle\Backup\Models\BackupDownload;
use PryTax\DataLifecycle\Backup\Models\BackupPolicy;
use PryTax\DataLifecycle\Backup\Models\BackupRun;
use PryTax\DataLifecycle\Console\BackupDispatchCommand;
use PryTax\DataLifecycle\Console\BackupImportCommand;
use PryTax\DataLifecycle\Console\BackupPruneCommand;
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
     * Models of the module, which the morph map names by aliases (`models.backups`): their permissions and audit
     * entries use them.
     *
     * @var array<int, class-string>
     */
    public const array MODELS = [
        Backup::class,
        BackupDownload::class,
        BackupPolicy::class,
        BackupRun::class,
    ];

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
     * Bootstrap the module's models, migrations, views, commands and schedule.
     *
     * @return void
     */
    public function boot(): void
    {
        Relation::morphMap(MorphServiceProvider::getMorphMap(self::MODELS));

        $this->loadMigrationsFrom(__DIR__ . '/database/migrations');
        $this->loadViewsFrom(__DIR__ . '/resources/views', 'data-lifecycle');

        if ($this->app->runningInConsole()) {
            $this->commands([
                BackupDispatchCommand::class,
                BackupImportCommand::class,
                BackupPruneCommand::class,
                BackupSnapshotCommand::class,
            ]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command(BackupDispatchCommand::class)->everyFiveMinutes()->onOneServer()->withoutOverlapping();
            $schedule->command(BackupPruneCommand::class)->dailyAt('05:30')->onOneServer();
        });
    }
}
