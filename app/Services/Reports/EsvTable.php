<?php

namespace PryTax\Services\Reports;

use Carbon\CarbonImmutable;
use PryTax\Enums\EsvStatus;
use PryTax\Enums\TaxAllocation;
use PryTax\Enums\TaxPaymentStatus;
use PryTax\Enums\TaxType;
use PryTax\Helpers\Decimal;
use PryTax\Models\EsvObligation;
use PryTax\Models\Profile;
use PryTax\Models\TaxPayment;
use PryTax\Services\Taxes\DeadlineScheduler;
use PryTax\Services\Taxes\EsvObligationScheduler;
use PryTax\Services\Taxes\TaxReconciler;
use PryTax\Services\Taxes\TaxRuleResolver;

/**
 * Class EsvTable.
 *
 * The year of ЄСВ: its 12 months, each due or exempt, with what was paid for it, grouped by quarter with the
 * quarter's deadline, payment date, pending payments and running balance, and the year's accrued, paid and remaining
 * amounts. A payment of a month counts for that month; a payment of a quarter covers its due months in order. The
 * balances are TaxReconciler's, so they agree with Податки: they start from the opening balance, and paying more than
 * a quarter's minimum leaves a credit, which carries to the next quarters (a decision of the owner, which design.md's
 * "paid above minimum" gave way to).
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Services\Reports
 */
class EsvTable
{
    /**
     * EsvTable constructor.
     *
     * @param EsvObligationScheduler $scheduler
     * @param DeadlineScheduler $deadlines
     * @param TaxRuleResolver $rules
     * @param TaxReconciler $reconciler
     */
    public function __construct(
        protected EsvObligationScheduler $scheduler,
        protected DeadlineScheduler $deadlines,
        protected TaxRuleResolver $rules,
        protected TaxReconciler $reconciler
    ) {}

    /**
     * Get the ЄСВ table of the profile's year.
     *
     * @param Profile $profile
     * @param int $year
     *
     * @return array<string, mixed>
     */
    public function forYear(Profile $profile, int $year): array
    {
        $obligations = $this->scheduler->ensureYear($profile, $year);

        $payments = TaxPayment::query()
            ->where('tax_type', TaxType::SocialContribution->value)
            ->where('period_year', $year)
            ->where('status', TaxPaymentStatus::Paid->value)
            ->get();

        $paidByMonth = array_fill_keys(range(1, 12), Decimal::zero());

        foreach ($payments->where('allocation', TaxAllocation::Month) as $payment) {
            $paidByMonth[$payment->period_month] = $paidByMonth[$payment->period_month]->plus(Decimal::of($payment->amount));
        }

        // A quarter's payment covers its due months in order, and the rest goes to its last month
        foreach ($payments->where('allocation', TaxAllocation::Quarter) as $payment) {
            $left = Decimal::of($payment->amount);
            $months = range(($payment->period_quarter - 1) * 3 + 1, $payment->period_quarter * 3);

            foreach ($months as $month) {
                $due = Decimal::of($obligations->get($month)?->status === EsvStatus::Due ? $obligations->get($month)->amount_due : '0');
                $take = Decimal::max(Decimal::min($left, $due->minus($paidByMonth[$month])), 0);
                $paidByMonth[$month] = $paidByMonth[$month]->plus($take);
                $left = $left->minus($take);
            }

            $paidByMonth[end($months)] = $paidByMonth[end($months)]->plus($left);
        }

        $reconciled = $this->reconciler->forYear($profile, TaxType::SocialContribution, $year);
        $series = collect($reconciled['quarters'])->keyBy('quarter');

        $quarters = [];
        $accrued = Decimal::zero();
        $paid = Decimal::zero();

        for ($quarter = 1; $quarter <= 4; $quarter++) {
            $period = $this->deadlines->ensure($profile, $year, $quarter);
            $months = [];
            $quarterOwed = Decimal::zero();
            $quarterPaid = Decimal::zero();

            foreach (range(($quarter - 1) * 3 + 1, $quarter * 3) as $month) {
                /** @var EsvObligation|null $obligation */
                $obligation = $obligations->get($month);
                $due = $obligation?->status === EsvStatus::Due ? Decimal::of($obligation->amount_due) : Decimal::zero();

                $months[] = [
                    'month' => $month,
                    'obligation_id' => $obligation?->id,
                    'status' => $obligation?->status->value,
                    'amount_due' => Decimal::money($due),
                    'paid' => Decimal::money($paidByMonth[$month]),
                    'note' => $obligation?->note,
                ];

                $quarterOwed = $quarterOwed->plus($due);
                $quarterPaid = $quarterPaid->plus($paidByMonth[$month]);
            }

            $lastPaidAt = $payments->where('period_quarter', $quarter)->max(fn (TaxPayment $payment) => $payment->paid_at?->toDateString());

            $reconciledQuarter = $series->get($quarter);
            $balance = $reconciledQuarter === null ? null : Decimal::of($reconciledQuarter['balance']);

            $quarters[] = [
                'quarter' => $quarter,
                'due_at' => $period->esv_due_at->toDateString(),
                'is_annual' => $quarter === 4,
                'owed' => Decimal::money($quarterOwed),
                'paid' => Decimal::money($quarterPaid),
                'pending' => $reconciledQuarter['pending'] ?? '0.00',
                'paid_at' => $lastPaidAt,
                // Running, with the opening balance and the credit of earlier quarters
                'balance' => Decimal::moneyOrNull($balance),
                'state' => $reconciledQuarter['state'] ?? null,
                // By its own payments, or by the credit, which earlier quarters carried to it
                'is_paid' => $quarterPaid->isGreaterThanOrEqualTo($quarterOwed) || ($balance?->isPositiveOrZero() ?? false),
                'months' => $months,
            ];

            $accrued = $accrued->plus($quarterOwed);
            $paid = $paid->plus($quarterPaid);
        }

        $rule = $this->rules->find(CarbonImmutable::create($year));

        return [
            'year' => $year,
            'monthly' => Decimal::money($rule?->esv_monthly),
            'min_wage' => Decimal::money($rule?->min_wage),
            'accrued' => Decimal::money($accrued),
            'paid' => Decimal::money($paid),
            'opening_balance' => $reconciled['opening_balance'],
            // The running balance today (at the year's end, for a past year): negative is still to pay
            'balance' => $reconciled['balance'],
            'remaining' => Decimal::money(Decimal::max(Decimal::of($reconciled['balance'])->negated(), 0)),
            'quarters' => $quarters,
        ];
    }
}
