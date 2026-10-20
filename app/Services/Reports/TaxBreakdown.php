<?php

namespace PryTax\Services\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use PryTax\Enums\EsvStatus;
use PryTax\Enums\TaxAllocation;
use PryTax\Enums\TaxPaymentStatus;
use PryTax\Enums\TaxType;
use PryTax\Helpers\Decimal;
use PryTax\Models\EsvObligation;
use PryTax\Models\Payment;
use PryTax\Models\Profile;
use PryTax\Models\Refund;
use PryTax\Models\TaxPayment;
use PryTax\Services\Taxes\DeadlineScheduler;
use PryTax\Services\Taxes\TaxLiabilityCalculator;
use PryTax\Services\Taxes\TaxReconciler;
use PryTax\Services\Taxes\TaxRuleResolver;

/**
 * Class TaxBreakdown.
 *
 * The views of ЄП and ВЗ by receipt and by month (design.md §8.2). By receipt: each receipt of the quarter with its
 * hints, its own tax payments and whether they are paid, covered by a month's or the quarter's payment, pending or
 * unpaid; each refund with the hints it takes back; the month and quarter payments as "covers N receipts"; and the
 * quarter's check of the owed amounts against the sum of the hints. By month: each month's income, the sum of its
 * hints as what it owes, its standalone ЄП and ВЗ at the month's rule, the tax payments mapped to it, its ЄСВ month,
 * and the state, deadline and running balance of its quarter (TaxReconciler).
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
     * @param TaxReconciler $reconciler
     */
    public function __construct(
        protected TaxLiabilityCalculator $liabilities,
        protected DeadlineScheduler $deadlines,
        protected TaxRuleResolver $rules,
        protected TaxReconciler $reconciler
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
                $covering = $taxPayments->filter(fn (TaxPayment $tax) => $tax->allocation === TaxAllocation::Quarter
                    || ($tax->allocation === TaxAllocation::Month && $tax->period_month === $payment->period_month));

                return [
                    'type' => 'payment',
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
                    'single_tax' => $this->taxOf(
                        $payment->single_tax_uah,
                        $own->where('tax_type', TaxType::SingleTax),
                        $covering->where('tax_type', TaxType::SingleTax)
                    ),
                    'military_levy' => $this->taxOf(
                        $payment->military_levy_uah,
                        $own->where('tax_type', TaxType::MilitaryLevy),
                        $covering->where('tax_type', TaxType::MilitaryLevy)
                    ),
                ];
            });

        $count = $rows->count();

        $refunds = Refund::query()
            ->where('period_year', $year)
            ->where('period_quarter', $quarter)
            ->with(['payment.invoice.client', 'taxRule'])
            ->orderBy('refunded_at')
            ->get()
            ->map(fn (Refund $refund) => [
                'type' => 'refund',
                'refund_id' => $refund->id,
                'payment_id' => $refund->payment_id,
                'received_at' => $refund->refunded_at->toDateString(),
                'invoice_id' => $refund->payment?->invoice_id,
                'invoice_number' => $refund->payment?->invoice?->number,
                'client_name' => $refund->payment?->invoice?->client?->name,
                'description' => $refund->reason,
                'currency' => $refund->currency,
                'amount' => Decimal::money(Decimal::of($refund->amount)->negated()),
                'nbu_rate' => $refund->nbu_rate,
                'uah_amount' => Decimal::money(Decimal::of($refund->uah_amount)->negated()),
                // The hints, which the refund takes back, so the rows add up to the quarter's sum of hints
                'single_tax' => ['hint' => Decimal::money(Decimal::times($refund->uah_amount, $refund->taxRule->single_tax_rate)->negated())],
                'military_levy' => ['hint' => Decimal::money(Decimal::times($refund->uah_amount, $refund->taxRule->military_levy_rate)->negated())],
            ]);

        return [
            'year' => $year,
            'quarter' => $quarter,
            'tax_due_at' => $period->tax_due_at->toDateString(),
            'rows' => $rows->concat($refunds)->sortBy('received_at')->values()->all(),
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
     * Get ЄП and ВЗ of the year by month, with the year's ЄСВ months.
     *
     * @param Profile $profile
     * @param int $year
     *
     * @return array{year: int, months: array<int, array<string, mixed>>}
     */
    public function byMonth(Profile $profile, int $year): array
    {
        $quarters = [];

        foreach (TaxType::cases() as $type) {
            $quarters[$type->value] = collect($this->reconciler->forYear($profile, $type, $year)['quarters'])->keyBy('quarter');
        }

        $taxPayments = TaxPayment::query()
            ->where('period_year', $year)
            ->whereNotNull('period_month')
            ->get();

        $esv = EsvObligation::query()->where('year', $year)->get()->keyBy('month');

        $months = [];

        foreach ($this->liabilities->incomeByMonth($profile, $year) as $month => $income) {
            $rule = $this->rules->find(CarbonImmutable::create($year, $month)->endOfMonth());
            $quarter = intdiv($month - 1, 3) + 1;
            $inMonth = $taxPayments->where('period_month', $month);
            $obligation = $esv->get($month);

            $months[] = [
                'month' => $month,
                'quarter' => $quarter,
                'income' => $income['income'],
                'count' => $income['count'],
                'single_tax' => [
                    'owed' => $income['single_tax_hints'],
                    'standalone' => Decimal::money(Decimal::times($income['income'], $rule?->single_tax_rate)),
                    ...$this->paidOf($inMonth->where('tax_type', TaxType::SingleTax)),
                    ...$this->quarterOf($quarters[TaxType::SingleTax->value]->get($quarter)),
                ],
                'military_levy' => [
                    'owed' => $income['military_levy_hints'],
                    'standalone' => Decimal::money(Decimal::times($income['income'], $rule?->military_levy_rate)),
                    ...$this->paidOf($inMonth->where('tax_type', TaxType::MilitaryLevy)),
                    ...$this->quarterOf($quarters[TaxType::MilitaryLevy->value]->get($quarter)),
                ],
                'social_contribution' => [
                    'status' => $obligation?->status->value,
                    'owed' => Decimal::money($obligation?->status === EsvStatus::Due ? $obligation->amount_due : 0),
                    ...$this->paidOf($inMonth->where('tax_type', TaxType::SocialContribution)),
                    ...$this->quarterOf($quarters[TaxType::SocialContribution->value]->get($quarter)),
                ],
            ];
        }

        return ['year' => $year, 'months' => $months];
    }

    /**
     * Describe a receipt's tax: its hint, its own tax payments, and whether it is paid by them, covered by a month's
     * or the quarter's paid payment, pending or unpaid.
     *
     * @param string $hint
     * @param Collection<int, TaxPayment> $payments
     * @param Collection<int, TaxPayment> $covering
     *
     * @return array<string, mixed>
     */
    protected function taxOf(string $hint, $payments, $covering): array
    {
        $paid = Decimal::sum($payments->filter(fn (TaxPayment $payment) => $payment->status === TaxPaymentStatus::Paid)->pluck('amount'));
        $sent = fn ($taxes) => $taxes->contains(fn (TaxPayment $payment) => $payment->status === TaxPaymentStatus::Sent);

        $status = match (true) {
            $paid->isGreaterThanOrEqualTo(Decimal::of($hint)) => 'paid',
            $covering->contains(fn (TaxPayment $payment) => $payment->status === TaxPaymentStatus::Paid) => 'covered',
            $sent($payments) || $sent($covering) => 'pending',
            default => 'unpaid',
        };

        return [
            'hint' => $hint,
            'status' => $status,
            'payments' => $payments->map(fn (TaxPayment $payment) => [
                'id' => $payment->id,
                'amount' => $payment->amount,
                'status' => $payment->status->value,
                'paid_at' => $payment->paid_at?->toDateString(),
            ])->values()->all(),
        ];
    }

    /**
     * Get what the tax payments of a month paid and keep pending.
     *
     * @param Collection<int, TaxPayment> $payments
     *
     * @return array{paid: string, pending: string}
     */
    protected function paidOf($payments): array
    {
        $sum = fn (TaxPaymentStatus $status) => Decimal::money(Decimal::sum($payments->filter(fn (TaxPayment $payment) => $payment->status === $status)->pluck('amount')));

        return [
            'paid' => $sum(TaxPaymentStatus::Paid),
            'pending' => $sum(TaxPaymentStatus::Sent),
        ];
    }

    /**
     * Get the state, deadline and running balance of a month's quarter.
     *
     * @param array<string, mixed>|null $quarter
     *
     * @return array{quarter_state: string|null, due_at: string|null, quarter_balance: string|null}
     */
    protected function quarterOf(?array $quarter): array
    {
        return [
            'quarter_state' => $quarter['state'] ?? null,
            'due_at' => $quarter['due_at'] ?? null,
            'quarter_balance' => $quarter['balance'] ?? null,
        ];
    }
}
