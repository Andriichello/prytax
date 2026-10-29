<?php

namespace PryTax\Services\Rates;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use PryTax\Exceptions\ExchangeRateUnavailableException;
use PryTax\Models\Account;
use PryTax\Models\AccountTransfer;
use PryTax\Models\Contract;
use PryTax\Models\Invoice;
use PryTax\Models\Payment;
use PryTax\Models\Profile;
use PryTax\Models\Refund;

/**
 * Class DailyRateFetcher.
 *
 * Caches the NBU rates of a day for every currency, which the accounts, contracts and invoices of active profiles
 * use, so the receipts of the day find them without calling the NBU (design.md §10). It also retries the NBU for the
 * days of receipts, refunds and transfers saved at a manual rate, so they can take the NBU's rate, once it is
 * published, and fetches the days of transfers' foreign fees, which their losses count in UAH. `prytax:fetch-rates`
 * runs it nightly; the desktop app queues it on launch (FetchRatesJob), as it may be closed at night.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Services\Rates
 */
class DailyRateFetcher
{
    /**
     * DailyRateFetcher constructor.
     *
     * @param NbuRateProvider $rates
     */
    public function __construct(protected NbuRateProvider $rates) {}

    /**
     * Fetch the rates of the day and of the days of manual rates, which aren't cached yet.
     *
     * @param CarbonImmutable $date
     *
     * @return array{fetched: array<int, array{currency: string, date: string, rate: string}>, failed: array<int, string>}
     */
    public function fetch(CarbonImmutable $date): array
    {
        $profiles = Profile::query()->withoutGlobalScope(Profile::ORGANIZATION_SCOPE)->where('is_active', true)->pluck('id');

        $days = $this->currencies($profiles)
            ->map(fn (string $currency) => [$currency, $date->toDateString()])
            ->merge($this->daysOfManualRates($profiles))
            ->unique(fn (array $day) => implode(':', $day))
            ->filter(fn (array $day) => !$this->rates->cached($day[0], CarbonImmutable::parse($day[1])));

        $result = ['fetched' => [], 'failed' => []];

        foreach ($days as [$currency, $day]) {
            try {
                $rate = $this->rates->fetch($currency, CarbonImmutable::parse($day));
                $result['fetched'][] = ['currency' => $currency, 'date' => $day, 'rate' => (string) $rate->rate];
            } catch (ExchangeRateUnavailableException $exception) {
                $result['failed'][] = $exception->getMessage();
            }
        }

        return $result;
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
     * Get the currencies and days of the profiles' receipts, refunds and transfers, which were saved at a manual rate,
     * and of the foreign fees of transfers without a UAH leg.
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

        // Foreign fees of transfers without a UAH leg are counted in UAH at the NBU rate of their day
        $fees = AccountTransfer::query()
            ->withoutGlobalScope(AccountTransfer::PROFILE_SCOPE)
            ->whereIn('profile_id', $profiles)
            ->whereNull('nbu_rate')
            ->whereNotNull('fee_currency')
            ->where('fee_currency', '<>', 'UAH')
            ->get(['fee_currency', 'received_at'])
            ->map(fn (AccountTransfer $transfer) => [$transfer->fee_currency, $transfer->received_at->toDateString()]);

        return $payments->merge($refunds)->merge($transfers)->merge($fees)->values();
    }
}
