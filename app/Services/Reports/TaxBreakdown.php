<?php

namespace PryTax\Services\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use PryTax\Enums\TaxAllocation;
use PryTax\Enums\TaxType;
use PryTax\Helpers\Decimal;
use PryTax\Models\Payment;
use PryTax\Models\Profile;
use PryTax\Models\TaxPayment;
use PryTax\Services\Taxes\DeadlineScheduler;
use PryTax\Services\Taxes\TaxLiabilityCalculator;
use PryTax\Services\Taxes\TaxRuleResolver;

/**
 * Class TaxBreakdown.
 *
 * The views of ЄП and ВЗ by receipt and by month (design.md §8.2). By receipt: each receipt of the quarter with its
 * hints and its own tax payments, the month and quarter payments as "covers N receipts", and the quarter's check of
 * the owed amounts against the sum of the hints. By month: each month's income, its standalone ЄП and ВЗ, and the tax
 * payments mapped to it.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Services\Reports
 */
class TaxBreakdown
{
    /**
     * TaxBreakdown constructor.
     *
     * @param TaxLiabilityCalculator $liabilities
     * @param DeadlineScheduler $deadlines
     * @param TaxRuleResolver $rules
     */
    public function __construct(
        protected TaxLiabilityCalculator $liabilities,
        protected DeadlineScheduler $deadlines,
        protected TaxRuleResolver $rules
    ) {}

    /**
     * Get ЄП and ВЗ of the quarter by receipt.
     *
     * @param Profile $profile
     * @param int $year
     * @param int $quarter
     *
     * @return array<string, mixed>
     */
    public function byPayment(Profile $profile, int $year, int $quarter): array
    {
        $period = $this->deadlines->ensure($profile, $year, $quarter);
        $liability = $this->liabilities->forQuarter($profile, $year, $quarter);

        $taxPayments = TaxPayment::query()
            ->where('period_year', $year)
            ->where('period_quarter', $quarter)
            ->whereIn('tax_type', [TaxType::SingleTax->value, TaxType::MilitaryLevy->value])
            ->orderBy('paid_at')
            ->get();

        $rows = Payment::query()
            ->inQuarter($year, $quarter)
            ->chronologically()
            ->with('invoice.client')
            ->get()
            ->map(function (Payment $payment) use ($taxPayments) {
                $own = $taxPayments->where('allocation', TaxAllocation::Payment)->where('payment_id', $payment->id);

                return [
                    'payment_id' => $payment->id,
                    'received_at' => $payment->received_at->toDateString(),
                    'invoice_id' => $payment->invoice_id,
                    'invoice_number' => $payment->invoice?->number,
                    'client_name' => $payment->invoice?->client?->name,
                    'description' => $payment->description,
                    'currency' => $payment->currency,
                    'amount' => $payment->amount,
                    'nbu_rate' => $payment->nbu_rate,
                    'uah_amount' => $payment->uah_amount,
                    'single_tax' => $this->taxOf($payment->single_tax_uah, $own->where('tax_type', TaxType::SingleTax)),
                    'military_levy' => $this->taxOf($payment->military_levy_uah, $own->where('tax_type', TaxType::MilitaryLevy)),
                ];
            });

        $count = $rows->count();

        return [
            'year' => $year,
            'quarter' => $quarter,
            'tax_due_at' => $period->tax_due_at->toDateString(),
            'rows' => $rows->values()->all(),
            'covering' => $taxPayments
                ->whereIn('allocation', [TaxAllocation::Month, TaxAllocation::Quarter])
                ->map(fn (TaxPayment $payment) => [
                    'id' => $payment->id,
                    'tax_type' => $payment->tax_type->value,
                    'allocation' => $payment->allocation->value,
                    'period_month' => $payment->period_month,
                    'amount' => $payment->amount,
                    'status' => $payment->status->value,
                    'paid_at' => $payment->paid_at?->toDateString(),
                    'covers' => $payment->allocation === TaxAllocation::Quarter
                        ? $count
                        : $rows->filter(fn (array $row) => (int) substr($row['received_at'], 5, 2) === $payment->period_month)->count(),
                ])
                ->values()
                ->all(),
            'check' => [
                'single_tax' => [
                    'owed' => $liability['single_tax']['owed'],
                    'hints_sum' => $liability['single_tax']['hints_sum'],
                    'delta' => $liability['single_tax']['hints_delta'],
                ],
                'military_levy' => [
                    'owed' => $liability['military_levy']['owed'],
                    'hints_sum' => $liability['military_levy']['hints_sum'],
                    'delta' => $liability['military_levy']['hints_delta'],
                ],
            ],
        ];
    }

    /**
     * Get ЄП and ВЗ of the year by month.
     *
     * @param Profile $profile
     * @param int $year
     *
     * @return array{year: int, months: array<int, array<string, mixed>>}
     */
    public function byMonth(Profile $profile, int $year): array
    {
        $rule = $this->rules->find(CarbonImmutable::create($year, 12, 31));

        $taxPayments = TaxPayment::query()
            ->where('period_year', $year)
            ->whereIn('tax_type', [TaxType::SingleTax->value, TaxType::MilitaryLevy->value])
            ->whereNotNull('period_month')
            ->get();

        $months = [];

        foreach ($this->liabilities->incomeByMonth($profile, $year) as $month => $income) {
            $inMonth = $taxPayments->where('period_month', $month);

            $months[] = [
                'month' => $month,
                'income' => $income['income'],
                'count' => $income['count'],
                'single_tax' => $this->monthTax($income['income'], $rule?->single_tax_rate, $inMonth->where('tax_type', TaxType::SingleTax)),
                'military_levy' => $this->monthTax($income['income'], $rule?->military_levy_rate, $inMonth->where('tax_type', TaxType::MilitaryLevy)),
            ];
        }

        return ['year' => $year, 'months' => $months];
    }

    /**
     * Describe a receipt's tax: its hint and its own tax payments.
     *
     * @param string $hint
     * @param Collection<int, TaxPayment> $payments
     *
     * @return array<string, mixed>
     */
    protected function taxOf(string $hint, $payments): array
    {
        return [
            'hint' => $hint,
            'payments' => $payments->map(fn (TaxPayment $payment) => [
                'id' => $payment->id,
                'amount' => $payment->amount,
                'status' => $payment->status->value,
                'paid_at' => $payment->paid_at?->toDateString(),
            ])->values()->all(),
        ];
    }

    /**
     * Describe a month's tax: the standalone amount of its income and the tax payments mapped to it.
     *
     * @param string $income
     * @param string|null $rate
     * @param Collection<int, TaxPayment> $payments
     *
     * @return array<string, mixed>
     */
    protected function monthTax(string $income, ?string $rate, $payments): array
    {
        $sum = fn (string $status) => Decimal::money(Decimal::sum($payments->filter(fn (TaxPayment $payment) => $payment->status->value === $status)->pluck('amount')));

        return [
            'standalone' => Decimal::money(Decimal::times($income, $rate)),
            'paid' => $sum('paid'),
            'pending' => $sum('sent'),
        ];
    }
}
