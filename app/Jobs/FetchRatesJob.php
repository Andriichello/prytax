<?php

namespace PryTax\Jobs;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use PryTax\Services\Rates\DailyRateFetcher;

/**
 * Class FetchRatesJob.
 *
 * The NBU rates of a day, which the desktop app queues on launch (APP-BUILDS-PLAN.md, phase 3, step 7), as it may be
 * closed at 01:00, when `prytax:fetch-rates` runs. Offline (on a train, during an outage), a day that can't be
 * fetched puts the job back for a while (1, 5, 15 minutes, then every 30) rather than failing it, for 12 hours; a
 * receipt meanwhile takes a manual rate, as it always may. One at a time: a second launch doesn't queue another.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Jobs
 */
class FetchRatesJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Seconds to wait before the next attempt, after each failed one; the last one repeats.
     *
     * @var array<int, int>
     */
    public const array BACKOFF = [60, 300, 900, 1800];

    /**
     * Attempts: as many as fit before `retryUntil()`.
     *
     * @var int
     */
    public int $tries = 0;

    /**
     * Seconds, for which a second job of the same isn't queued.
     *
     * @var int
     */
    public int $uniqueFor = 3600;

    /**
     * FetchRatesJob constructor.
     *
     * @param string $date The day, YYYY-MM-DD
     */
    public function __construct(public string $date) {}

    /**
     * Get the time after which the job isn't attempted again.
     *
     * @return DateTimeInterface
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(12);
    }

    /**
     * Fetch the day's rates, and try again later, when some can't be fetched.
     *
     * @param DailyRateFetcher $fetcher
     *
     * @return void
     */
    public function handle(DailyRateFetcher $fetcher): void
    {
        $result = $fetcher->fetch(CarbonImmutable::parse($this->date));

        if ($result['failed'] !== []) {
            $this->release(self::BACKOFF[min($this->attempts(), count(self::BACKOFF)) - 1]);
        }
    }
}
