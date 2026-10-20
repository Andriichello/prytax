<?php

namespace PryTax\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use PryTax\Models\Profile;
use PryTax\Services\Alerts\AlertSync;

/**
 * Class SyncAlerts.
 *
 * Syncs the notifications of active profiles with their conditions, daily, for the ones that change with time alone:
 * deadlines, overdue invoices, contracts ending and money left on platforms (design.md §9).
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Console\Commands
 */
#[Signature('prytax:sync-alerts {--profile= : Id of one profile}')]
#[Description('Sync the notifications of active profiles')]
class SyncAlerts extends Command
{
    /**
     * Execute the console command.
     *
     * @param AlertSync $alerts
     *
     * @return int
     */
    public function handle(AlertSync $alerts): int
    {
        Profile::query()
            ->withoutGlobalScope(Profile::ORGANIZATION_SCOPE)
            ->where('is_active', true)
            ->when($this->option('profile'), fn ($query, $id) => $query->whereKey($id))
            ->each(function (Profile $profile) use ($alerts) {
                $result = $alerts->sync($profile);
                $this->line("{$profile->short_name}: {$result['opened']} opened, {$result['resolved']} resolved");
            });

        return self::SUCCESS;
    }
}
