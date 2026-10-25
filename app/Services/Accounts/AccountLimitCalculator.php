<?php

namespace PryTax\Services\Accounts;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use PryTax\Enums\AccountKind;
use PryTax\Enums\LimitPeriod;
use PryTax\Enums\PaymentKind;
use PryTax\Helpers\Decimal;
use PryTax\Models\Account;
use PryTax\Models\Payment;

/**
 * Class AccountLimitCalculator.
 *
 * Progress towards an account's limit (design.md §3, accounts): the client receipts into it (as Payoneer's $10 000 a
 * year), within the current calendar month, quarter or year.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Services\Accounts
 */
class AccountLimitCalculator
{
    /**
     * Get the account's progress towards its limit, or null, when it has none.
     *
     * @param Account $account
     * @param CarbonInterface|null $today
     *
     * @return array{used: string, limit: string, remaining: string, percent: string, period: string, from: string, to: string}|null
     */
    public function progress(Account $account, ?CarbonInterface $today = null): ?array
    {
        // A personal account isn't a business one, so a limit of it isn't tracked
        if ($account->limit_amount === null || $account->limit_period === null || $account->kind === AccountKind::Personal) {
            return null;
        }

        [$from, $to] = $this->window($account->limit_period, $today);

        $used = Decimal::of((string) Payment::query()
            ->where('received_to_account_id', $account->getKey())
            ->where('kind', '<>', PaymentKind::FxGain->value)
            ->whereBetween('received_at', [$from->toDateString(), $to->toDateString()])
            ->sum('received_amount'));

        $limit = Decimal::of($account->limit_amount);

        return [
            'used' => Decimal::money($used),
            'limit' => Decimal::money($limit),
            'remaining' => Decimal::money(Decimal::max($limit->minus($used), 0)),
            'percent' => Decimal::percent($used, $limit, 0),
            'period' => $account->limit_period->value,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ];
    }

    /**
     * Get the first and the last day of the limit's calendar window.
     *
     * @param LimitPeriod $period
     * @param CarbonInterface|null $today
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function window(LimitPeriod $period, ?CarbonInterface $today = null): array
    {
        $today = CarbonImmutable::parse(($today ?? now())->toDateString());

        return match ($period) {
            LimitPeriod::Month => [$today->startOfMonth(), $today->endOfMonth()->startOfDay()],
            LimitPeriod::Quarter => [$today->startOfQuarter(), $today->endOfQuarter()->startOfDay()],
            LimitPeriod::Year => [$today->startOfYear(), $today->endOfYear()->startOfDay()],
        };
    }
}
