<?php

namespace PryTax\Services\Reports;

use PryTax\Enums\TaxType;
use PryTax\Helpers\Decimal;
use PryTax\Models\Profile;
use PryTax\Services\Taxes\DeadlineScheduler;
use PryTax\Services\Taxes\TaxLiabilityCalculator;
use PryTax\Services\Taxes\TaxReconciler;

/**
 * Class DeclarationFigures.
 *
 * The figures of a quarter's declaration in its order (design.md §8.3): the year-to-date income, and for ЄП and ВЗ
 * the year-to-date liability, the one declared for the previous quarter and the amount to pay now; the annual
 * declaration adds the ЄСВ months (Додаток 1). Also the deadlines and what remains to pay of each tax.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Services\Reports
 */
class DeclarationFigures
{
    /**
     * DeclarationFigures constructor.
     *
     * @param TaxLiabilityCalculator $liabilities
     * @param TaxReconciler $reconciler
     * @param DeadlineScheduler $deadlines
     * @param EsvTable $esv
     */
    public function __construct(
        protected TaxLiabilityCalculator $liabilities,
        protected TaxReconciler $reconciler,
        protected DeadlineScheduler $deadlines,
        protected EsvTable $esv
    ) {}

    /**
     * Get the declaration figures of the profile's quarter.
     *
     * @param Profile $profile
     * @param int $year
     * @param int $quarter
     *
     * @return array<string, mixed>
     */
    public function forQuarter(Profile $profile, int $year, int $quarter): array
    {
        $period = $this->deadlines->ensure($profile, $year, $quarter);
        $remaining = [];

        // The reconciliation comes first: it creates the tracked months of ЄСВ, which the liability counts
        foreach (TaxType::cases() as $type) {
            $series = collect($this->reconciler->forYear($profile, $type, $year)['quarters'])->firstWhere('quarter', $quarter);
            $balance = Decimal::of($series['balance'] ?? '0');

            $remaining[$type->value] = [
                'owed' => $series['owed'] ?? '0.00',
                'paid' => $series['paid'] ?? '0.00',
                'pending' => $series['pending'] ?? '0.00',
                'balance' => Decimal::money($balance),
                'to_pay' => Decimal::money($balance->isNegative() ? $balance->negated() : 0),
            ];
        }

        $liability = $this->liabilities->forQuarter($profile, $year, $quarter);
        $esv = $this->esv->forYear($profile, $year);

        return [
            'year' => $year,
            'quarter' => $quarter,
            'is_annual' => $quarter === 4,
            'period' => [
                'id' => $period->id,
                'declaration_status' => $period->declaration_status->value,
                'declaration_submitted_at' => $period->declaration_submitted_at?->toIso8601String(),
                'declaration_due_at' => $period->declaration_due_at->toDateString(),
                'tax_due_at' => $period->tax_due_at->toDateString(),
                'esv_due_at' => $period->esv_due_at->toDateString(),
                'is_locked' => $period->isLocked(),
                'notes' => $period->notes,
            ],
            'income_ytd' => $liability['ytd_income'],
            'single_tax' => [
                'rate' => $liability['single_tax']['rate'],
                'ytd' => $liability['single_tax']['ytd'],
                'previously_declared' => $liability['single_tax']['previous_ytd'],
                'to_pay' => $liability['single_tax']['owed'],
            ],
            'military_levy' => [
                'rate' => $liability['military_levy']['rate'],
                'ytd' => $liability['military_levy']['ytd'],
                'previously_declared' => $liability['military_levy']['previous_ytd'],
                'to_pay' => $liability['military_levy']['owed'],
            ],
            'esv' => $liability['esv'],
            // The quarter's ЄСВ with its payments, and for the annual declaration (Додаток 1) every month of the year
            'esv_quarter' => collect($esv['quarters'])->firstWhere('quarter', $quarter),
            'esv_months' => $quarter === 4 ? collect($esv['quarters'])->pluck('months')->flatten(1)->values()->all() : null,
            'income_cap' => $liability['cap'],
            'remaining' => $remaining,
        ];
    }
}
