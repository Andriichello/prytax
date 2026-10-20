<?php

namespace PryTax\Services\Reports;

use Brick\Math\BigDecimal;
use PryTax\Enums\PaymentKind;
use PryTax\Helpers\Decimal;
use PryTax\Models\AccountTransfer;
use PryTax\Models\Payment;
use PryTax\Models\Profile;
use PryTax\Services\Taxes\TaxLiabilityCalculator;
use PryTax\Services\Transfers\TransferMetrics;

/**
 * Class FeeStatistics.
 *
 * What the money paths cost a profile in a year (accounting.md): fees and FX spread in UAH by quarter, by account, by
 * client and by route, so the user sees which platform or payment flow is best, and each quarter's net income,
 * `Σ uah_amount − fees − ЄП − ВЗ − ЄСВ`. A receipt's fee is in UAH at its rate; a transfer's at its NBU rate or a
 * cached one, and a client's share of a transfer is pro-rata of its allocation. Spread is reported apart from fees, as
 * a conversion, not a commission.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Services\Reports
 */
class FeeStatistics
{
    /**
     * FeeStatistics constructor.
     *
     * @param TransferMetrics $transfers
     * @param TaxLiabilityCalculator $liabilities
     */
    public function __construct(
        protected TransferMetrics $transfers,
        protected TaxLiabilityCalculator $liabilities
    ) {}

    /**
     * Get the statistics of the profile's year.
     *
     * @param Profile $profile
     * @param int $year
     *
     * @return array<string, mixed>
     */
    public function forYear(Profile $profile, int $year): array
    {
        $quarters = array_fill_keys(range(1, 4), ['fees' => Decimal::zero(), 'spread' => Decimal::zero()]);
        $accounts = [];
        $clients = [];

        $receipts = Payment::query()
            ->where('period_year', $year)
            ->where('kind', '<>', PaymentKind::FxGain->value)
            ->with(['receivedToAccount', 'invoice.client'])
            ->get();

        foreach ($receipts as $payment) {
            $fee = $payment->received_currency === $payment->currency
                ? Decimal::times(Decimal::of($payment->amount)->minus(Decimal::of($payment->received_amount)), $payment->nbu_rate)
                : Decimal::zero();
            $spread = $payment->received_currency === 'UAH' && $payment->currency !== 'UAH'
                ? Decimal::times($payment->amount, $payment->nbu_rate)->minus(Decimal::of($payment->received_amount))
                : Decimal::zero();

            $quarters[$payment->period_quarter]['fees'] = $quarters[$payment->period_quarter]['fees']->plus($fee);
            $quarters[$payment->period_quarter]['spread'] = $quarters[$payment->period_quarter]['spread']->plus($spread);

            $this->add($accounts, $payment->received_to_account_id, $payment->receivedToAccount?->name, $fee, $spread, Decimal::of($payment->uah_amount), $payment->receivedToAccount?->currency);
            $this->add($clients, $payment->invoice?->client_id ?? 0, $payment->invoice?->client?->name, $fee, $spread, Decimal::of($payment->uah_amount));
        }

        $transfers = AccountTransfer::query()
            ->whereYear('received_at', $year)
            ->with(['fromAccount', 'allocations.payment.invoice.client'])
            ->get();

        $unknown = 0;

        foreach ($transfers as $transfer) {
            $fee = $this->transfers->feeInUah($transfer);
            $unknown += $fee === null ? 1 : 0;
            $fee ??= Decimal::zero();
            $spread = TransferMetrics::spread($transfer) ?? Decimal::zero();
            $quarter = $transfer->received_at->quarter;

            $quarters[$quarter]['fees'] = $quarters[$quarter]['fees']->plus($fee);
            $quarters[$quarter]['spread'] = $quarters[$quarter]['spread']->plus($spread);

            $this->add($accounts, $transfer->from_account_id, $transfer->fromAccount?->name, $fee, $spread, currency: $transfer->fromAccount?->currency);

            foreach ($transfer->allocations as $allocation) {
                $share = Decimal::divide($allocation->amount, $transfer->amount_sent, 8);
                $client = $allocation->payment?->invoice?->client;

                $this->add($clients, $client?->getKey() ?? 0, $client?->name, Decimal::round($fee->multipliedBy($share)), Decimal::round($spread->multipliedBy($share)));
            }
        }

        return [
            'year' => $year,
            'quarters' => array_values(array_map(fn (int $quarter) => $this->quarter($profile, $year, $quarter, $quarters[$quarter]), range(1, 4))),
            'by_account' => $this->listed($accounts),
            'by_client' => $this->listed($clients),
            'routes' => $this->transfers->forYear($profile, $year)['routes'],
            // Transfers, whose fees aren't counted, since the NBU rate of their currency isn't cached
            'unknown_fees_count' => $unknown,
        ];
    }

    /**
     * Describe a quarter: its income, fees, spread, taxes and net income, in UAH.
     *
     * @param Profile $profile
     * @param int $year
     * @param int $quarter
     * @param array{fees: BigDecimal, spread: BigDecimal} $costs
     *
     * @return array<string, mixed>
     */
    protected function quarter(Profile $profile, int $year, int $quarter, array $costs): array
    {
        $liability = $this->liabilities->forQuarter($profile, $year, $quarter);
        $income = Decimal::of($liability['quarter_income']);
        $taxes = Decimal::of($liability['single_tax']['owed'])
            ->plus(Decimal::of($liability['military_levy']['owed']))
            ->plus(Decimal::of($liability['esv']['owed']));

        return [
            'quarter' => $quarter,
            'income' => Decimal::money($income),
            'fees' => Decimal::money($costs['fees']),
            'spread' => Decimal::money($costs['spread']),
            'single_tax' => $liability['single_tax']['owed'],
            'military_levy' => $liability['military_levy']['owed'],
            'esv' => $liability['esv']['owed'],
            'net_income' => Decimal::money($income->minus($costs['fees'])->minus($taxes)),
        ];
    }

    /**
     * Add the costs to a group, such as an account (with its currency) or a client.
     *
     * @param array<int, array<string, mixed>> $groups
     * @param int $key
     * @param string|null $name
     * @param BigDecimal $fee
     * @param BigDecimal $spread
     * @param BigDecimal|null $income
     * @param string|null $currency
     *
     * @return void
     */
    protected function add(array &$groups, int $key, ?string $name, BigDecimal $fee, BigDecimal $spread, ?BigDecimal $income = null, ?string $currency = null): void
    {
        $group = $groups[$key] ?? [
            'id' => $key ?: null,
            'name' => $name,
            'currency' => $currency,
            'receipts' => 0,
            'income' => Decimal::zero(),
            'fees' => Decimal::zero(),
            'spread' => Decimal::zero(),
        ];

        $group['receipts'] += $income === null ? 0 : 1;
        $group['income'] = $group['income']->plus($income ?? Decimal::zero());
        $group['fees'] = $group['fees']->plus($fee);
        $group['spread'] = $group['spread']->plus($spread);

        $groups[$key] = $group;
    }

    /**
     * List the groups, the costliest first, with their loss in the income they brought.
     *
     * @param array<int, array<string, mixed>> $groups
     *
     * @return array<int, array<string, mixed>>
     */
    protected function listed(array $groups): array
    {
        return collect($groups)
            ->sort(fn (array $first, array $second) => $second['fees']->plus($second['spread'])->compareTo($first['fees']->plus($first['spread'])))
            ->map(fn (array $group) => [
                ...$group,
                'income' => Decimal::money($group['income']),
                'fees' => Decimal::money($group['fees']),
                'spread' => Decimal::money($group['spread']),
                'loss_percent' => $group['income']->isPositive()
                    ? Decimal::percent($group['fees']->plus($group['spread']), $group['income'], 2)
                    : null,
            ])
            ->values()
            ->all();
    }
}
