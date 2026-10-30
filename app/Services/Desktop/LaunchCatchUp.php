<?php

namespace PryTax\Services\Desktop;

use Illuminate\Support\Facades\Artisan;
use PryTax\Jobs\FetchRatesJob;

/**
 * Class LaunchCatchUp.
 *
 * What the desktop app catches up on, when it's launched (builds.md §4.7): it's rarely open at
 * 01:00, when the day's rates, tax periods and notifications are done on a server, so its queue does them now,
 * without delaying the window. Backups need nothing: a policy's missed window is due at the scheduler's next run.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Services\Desktop
 */
class LaunchCatchUp
{
    /**
     * Queue the day's catching up.
     *
     * @return void
     */
    public function dispatch(): void
    {
        FetchRatesJob::dispatch(now()->toDateString());
        Artisan::queue('prytax:schedule-tax-periods');
        Artisan::queue('prytax:sync-alerts');
    }
}
