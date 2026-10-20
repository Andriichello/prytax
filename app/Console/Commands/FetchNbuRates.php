<?php

namespace PryTax\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use PryTax\Exceptions\ExchangeRateUnavailableException;
use PryTax\Models\Account;
use PryTax\Models\AccountTransfer;
use PryTax\Models\Contract;
use PryTax\Models\Invoice;
use PryTax\Models\Payment;
use PryTax\Models\Profile;
use PryTax\Models\Refund;
use PryTax\Services\Rates\NbuRateProvider;

/**
 * Class FetchNbuRates.
 *
 * Caches the NBU rates of a day for every currency, which the accounts, contracts and invoices of active profiles
 * use, so the receipts of the day find them without calling the NBU (design.md §10). It also retries the NBU for the
 * days of receipts, refunds and transfers saved at a manual rate, so they can take the NBU's rate, once it is
 * published.
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
     * @param NbuRateProvider $rates
     *
     * @return int
     */
    public function handle(NbuRateProvider $rates): int
    {
        $date = CarbonImmutable::parse($this->option('date') ?? 'today');
        $profiles = Profile::query()->withoutGlobalScope(Profile::ORGANIZATION_SCOPE)->where('is_active', true)->pluck('id');

        $days = $this->currencies($profiles)
            ->map(fn (string $currency) => [$currency, $date->toDateString()])
            ->merge($this->daysOfManualRates($profiles))
            ->unique(fn (array $day) => implode(':', $day))
            ->filter(fn (array $day) => !$rates->cached($day[0], CarbonImmutable::parse($day[1])));

        $failed = 0;

        foreach ($days as [$currency, $day]) {
            try {
                $rate = $rates->fetch($currency, CarbonImmutable::parse($day));
                $this->line("$currency $day: {$rate->rate}");
            } catch (ExchangeRateUnavailableException $exception) {
                $failed++;
                $this->warn($exception->getMessage());
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Get the foreign currencies, which the accounts, contracts and invoices of the profiles use.
     *
     * @param Collection<int, int> $profiles
     *
     * @return Collection<int, string>
     */
    protected function currencies(Collection $profiles): Collection
    {
        return collect([Account::class, Contract::class, Invoice::class])
            ->flatMap(fn (string $model) => $model::query()
                ->withoutGlobalScope($model::PROFILE_SCOPE)
                ->whereIn('profile_id', $profiles)
                ->where('currency', '<>', 'UAH')
                ->distinct()
                ->pluck('currency'))
            ->unique()
            ->values();
    }

    /**
     * Get the currencies and days of the profiles' receipts, refunds and transfers, which were saved at a manual rate.
     *
     * @param Collection<int, int> $profiles
     *
     * @return Collection<int, array{0: string, 1: string}>
     */
    protected function daysOfManualRates(Collection $profiles): Collection
    {
        $payments = Payment::query()
            ->withoutGlobalScope(Payment::PROFILE_SCOPE)
            ->whereIn('profile_id', $profiles)
            ->where('rate_is_manual', true)
            ->get(['currency', 'nbu_rate_date'])
            ->map(fn (Payment $payment) => [$payment->currency, $payment->nbu_rate_date->toDateString()]);

        $refunds = Refund::query()
            ->withoutGlobalScope(Refund::PROFILE_SCOPE)
            ->whereIn('profile_id', $profiles)
            ->where('rate_is_manual', true)
            ->get(['currency', 'nbu_rate_date'])
            ->map(fn (Refund $refund) => [$refund->currency, $refund->nbu_rate_date->toDateString()]);

        $transfers = AccountTransfer::query()
            ->withoutGlobalScope(AccountTransfer::PROFILE_SCOPE)
            ->whereIn('profile_id', $profiles)
            ->where('rate_is_manual', true)
            ->get(['currency_sent', 'currency_received', 'received_at'])
            ->map(fn (AccountTransfer $transfer) => [
                $transfer->currency_sent === 'UAH' ? $transfer->currency_received : $transfer->currency_sent,
                $transfer->received_at->toDateString(),
            ]);

        return $payments->merge($refunds)->merge($transfers)->values();
    }
}
