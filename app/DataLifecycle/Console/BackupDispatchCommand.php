<?php

namespace PryTax\DataLifecycle\Console;

use Core\Models\Organization;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PryTax\DataLifecycle\Backup\BackupRuns;
use PryTax\DataLifecycle\Backup\Models\BackupPolicy;
use PryTax\DataLifecycle\Contracts\TenantRoot;
use Throwable;

/**
 * Class BackupDispatchCommand.
 *
 * What the scheduler runs every 5 minutes (backups.md §4.3): starts the scheduled run of every enabled policy, which
 * is due, and plans its next one. An organization without a policy gets the default one, whose first run is planned
 * in the window. Organizations without profiles have nothing to back up.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Console
 */
class BackupDispatchCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'prytax:backup:dispatch';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Start the scheduled backups, which are due';

    /**
     * Execute the console command.
     *
     * @param BackupRuns $runs
     * @param TenantRoot $root
     *
     * @return int
     */
    public function handle(BackupRuns $runs, TenantRoot $root): int
    {
        $withProfiles = DB::table($root->table())->whereNull('deleted_at')->distinct()->pluck($root->organizationColumn());

        Organization::query()
            ->whereIn('id', $withProfiles)
            ->whereNotIn('id', BackupPolicy::query()->withoutGlobalScopes()->select('organization_id'))
            ->each(function (Organization $organization) {
                $policy = BackupPolicy::of($organization->id);
                $policy->next_run_at = $policy->nextRunAfter(now());
                $policy->save();
            });

        $started = 0;

        BackupPolicy::query()->withoutGlobalScopes()
            ->where('enabled', true)
            ->where('next_run_at', '<=', now())
            ->whereIn('organization_id', $withProfiles)
            ->whereIn('organization_id', Organization::query()->select('id'))
            ->each(function (BackupPolicy $policy) use ($runs, &$started) {
                // Planned first, so a failure of one organization's start doesn't start it again every 5 minutes
                $policy->forceFill(['last_run_at' => now(), 'next_run_at' => $policy->nextRunAfter(now())])->saveQuietly();

                try {
                    $runs->scheduled($policy);
                    $started++;
                } catch (Throwable $exception) {
                    report($exception);
                    $this->error("Organization $policy->organization_id: {$exception->getMessage()}");
                }
            });

        $this->info("Started $started scheduled backups.");

        return self::SUCCESS;
    }
}
