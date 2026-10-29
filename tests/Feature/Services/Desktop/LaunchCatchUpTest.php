<?php

namespace Tests\Feature\Services\Desktop;

use Illuminate\Foundation\Console\QueuedCommand;
use Illuminate\Support\Facades\Queue;
use PryTax\Jobs\FetchRatesJob;
use PryTax\Services\Desktop\LaunchCatchUp;
use Tests\TestCase;

/**
 * Class LaunchCatchUpTest.
 *
 * What the desktop app catches up on at launch (APP-BUILDS-PLAN.md, phase 3, step 7): the day's rates, tax periods
 * and notifications, queued rather than done before the window opens.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Services\Desktop
 */
class LaunchCatchUpTest extends TestCase
{
    /**
     * Test that the day's rates, tax periods and notifications are queued.
     *
     * @return void
     */
    public function testQueuesTheDaysRatesTaxPeriodsAndNotifications(): void
    {
        Queue::fake();
        $this->travelTo('2026-10-20 09:00:00');

        app(LaunchCatchUp::class)->dispatch();

        Queue::assertPushed(FetchRatesJob::class, fn (FetchRatesJob $job) => $job->date === '2026-10-20');
        Queue::assertPushed(QueuedCommand::class, fn (QueuedCommand $job) => $job->displayName() === 'prytax:schedule-tax-periods');
        Queue::assertPushed(QueuedCommand::class, fn (QueuedCommand $job) => $job->displayName() === 'prytax:sync-alerts');
    }
}
