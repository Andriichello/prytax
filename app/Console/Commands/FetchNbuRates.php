<?php

namespace PryTax\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use PryTax\Exceptions\ExchangeRateUnavailableException;
use PryTax\Models\Account;
use PryTax\Models\Profile;
use PryTax\Services\Rates\NbuRateProvider;

/**
 * Class FetchNbuRates.
 *
 * Caches the NBU rates of a day for every currency, which the accounts of active profiles use, so the receipts of
 * the day find them without calling the NBU (design.md §10).
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Console\Commands
 */
#[Signature('prytax:fetch-rates {--date= : The day, YYYY-MM-DD; today by default}')]
#[Description('Cache the NBU rates of the currencies of active profiles')]
class FetchNbuRates extends Command
{
    /**
     * Execute the console command.
     *
     * @param NbuRateProvider $rates
     *
     * @return int
     */
    public function handle(NbuRateProvider $rates): int
    {
        $date = CarbonImmutable::parse($this->option('date') ?? 'today');
        $profiles = Profile::query()->withoutGlobalScope(Profile::ORGANIZATION_SCOPE)->where('is_active', true)->pluck('id');

        $currencies = Account::query()
            ->withoutGlobalScope(Account::PROFILE_SCOPE)
            ->whereIn('profile_id', $profiles)
            ->where('currency', '<>', 'UAH')
            ->distinct()
            ->pluck('currency');

        $failed = 0;

        foreach ($currencies as $currency) {
            try {
                $rate = $rates->fetch($currency, $date);
                $this->line("$currency {$date->toDateString()}: {$rate->rate}");
            } catch (ExchangeRateUnavailableException $exception) {
                $failed++;
                $this->warn($exception->getMessage());
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
