<?php

namespace PryTax\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use PryTax\Services\Rates\DailyRateFetcher;

/**
 * Class FetchNbuRates.
 *
 * Caches the NBU rates of a day (DailyRateFetcher): the currencies of active profiles, and the days of manual rates
 * and of transfers' foreign fees (design.md §10).
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Console\Commands
 */
#[Signature('prytax:fetch-rates {--date= : The day, YYYY-MM-DD; today by default}')]
#[Description('Cache the NBU rates of the currencies of active profiles, and of the days of manual rates')]
class FetchNbuRates extends Command
{
    /**
     * Execute the console command.
     *
     * @param DailyRateFetcher $fetcher
     *
     * @return int
     */
    public function handle(DailyRateFetcher $fetcher): int
    {
        $result = $fetcher->fetch(CarbonImmutable::parse($this->option('date') ?? 'today'));

        foreach ($result['fetched'] as $rate) {
            $this->line("{$rate['currency']} {$rate['date']}: {$rate['rate']}");
        }

        foreach ($result['failed'] as $message) {
            $this->warn($message);
        }

        return $result['failed'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
