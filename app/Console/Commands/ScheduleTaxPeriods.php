<?php

namespace PryTax\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use PryTax\Helpers\CurrentProfile;
use PryTax\Models\Profile;
use PryTax\Services\Taxes\DeadlineScheduler;
use PryTax\Services\Taxes\EsvObligationScheduler;

/**
 * Class ScheduleTaxPeriods.
 *
 * Creates the tax periods of the current and next quarter, and the months of ЄСВ of the year, of every active
 * profile (design.md §8.3). It is idempotent, so it runs daily.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Console\Commands
 */
#[Signature('prytax:schedule-tax-periods')]
#[Description('Create the tax periods and ЄСВ months of active profiles')]
class ScheduleTaxPeriods extends Command
{
    /**
     * Execute the console command.
     *
     * @param DeadlineScheduler $deadlines
     * @param EsvObligationScheduler $esv
     *
     * @return int
     */
    public function handle(DeadlineScheduler $deadlines, EsvObligationScheduler $esv): int
    {
        Profile::query()
            ->withoutGlobalScope(Profile::ORGANIZATION_SCOPE)
            ->where('is_active', true)
            ->each(function (Profile $profile) use ($deadlines, $esv) {
                CurrentProfile::run($profile, function () use ($profile, $deadlines, $esv) {
                    $deadlines->ensureCurrentAndNext($profile);
                    $esv->ensureYear($profile, now()->year);
                });
            });

        return self::SUCCESS;
    }
}
