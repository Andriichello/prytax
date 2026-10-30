<?php

namespace Tests\Feature\Jobs;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PryTax\Jobs\FetchRatesJob;
use PryTax\Models\ExchangeRate;
use PryTax\Services\Rates\DailyRateFetcher;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;

/**
 * Class FetchRatesJobTest.
 *
 * The rates of the day, which the desktop app queues on launch (builds.md §4.7): cached, when the
 * NBU answers; put back for a while, when it can't be reached, for up to 12 hours.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Jobs
 */
class FetchRatesJobTest extends TestCase
{
    use ActsInProfiles;
    use RefreshDatabase;

    /**
     * Test that the day's rates are cached, and the job is done.
     *
     * @return void
     */
    public function testCachesTheDaysRates(): void
    {
        $this->createAccount($this->createProfile(), ['currency' => 'USD']);
        Http::fake(['bank.gov.ua/*date=20261020*' => Http::response('[{"rate":42.0870,"cc":"USD"}]')]);
        $job = (new FetchRatesJob('2026-10-20'))->withFakeQueueInteractions();

        $job->handle(app(DailyRateFetcher::class));

        $job->assertNotReleased();
        $this->assertSame('42.0870', ExchangeRate::query()->where('currency', 'USD')->sole()->rate);
    }

    /**
     * Test that an unreachable NBU puts the job back for a minute, then longer, rather than failing it.
     *
     * @return void
     */
    public function testTriesAgainLaterWhenTheNbuCantBeReached(): void
    {
        $this->createAccount($this->createProfile(), ['currency' => 'USD']);
        Http::fake(['bank.gov.ua/*' => Http::failedConnection()]);
        $job = (new FetchRatesJob('2026-10-20'))->withFakeQueueInteractions();

        $job->handle(app(DailyRateFetcher::class));

        $job->assertReleased(60);
        $this->assertSame(0, ExchangeRate::query()->count());
    }

    /**
     * Test that the job is attempted for 12 hours after it was queued.
     *
     * @return void
     */
    public function testIsAttemptedForTwelveHours(): void
    {
        $this->travelTo('2026-10-20 09:00:00');

        $this->assertSame('2026-10-20 21:00:00', (new FetchRatesJob('2026-10-20'))->retryUntil()->format('Y-m-d H:i:s'));
    }
}
