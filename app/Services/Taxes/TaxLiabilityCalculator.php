<?php

namespace PryTax\Services\Taxes;

use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use PryTax\Enums\EsvStatus;
use PryTax\Helpers\Decimal;
use PryTax\Models\EsvObligation;
use PryTax\Models\Payment;
use PryTax\Models\Profile;
use PryTax\Models\Refund;
use PryTax\Models\TaxRule;

/**
 * Class TaxLiabilityCalculator.
 *
 * Liabilities of a profile's quarter, computed from its receipts and refunds on demand, never stored (design.md
 * §8.1). Income is the sum of the already rounded `uah_amount` of the receipts, less refunds. The year-to-date ЄП and
 * ВЗ, `ROUND_HALF_UP(Σ YTD × rate, 2)`, are the declaration's figures, and a quarter owes the difference between its
 * year-to-date figure and the previous quarter's: that is authoritative. The standalone `ROUND(Σ quarter × rate)` and
 * the sum of the per-receipt hints are cross-checks, shown with their kopeck deltas. A rate, which changes during a
 * year, splits the income into segments by the receipts' tax rules.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Services\Taxes
 */
class TaxLiabilityCalculator
{
    /**
     * TaxLiabilityCalculator constructor.
     *
     * @param TaxRuleResolver $rules
     */
    public function __construct(
        protected TaxRuleResolver $rules
    ) {}

    /**
     * Get the liabilities of the profile's quarter.
     *
     * @param Profile $profile
     * @param int $year
     * @param int $quarter
     *
     * @return array{
     *     year: int,
     *     quarter: int,
     *     quarter_income: string,
     *     ytd_income: string,
     *     previous_ytd_income: string,
     *     payments_count: int,
     *     single_tax: array<string, string>,
     *     military_levy: array<string, string>,
     *     esv: array<string, string|int>,
     *     cap: array<string, string>,
     * }
     */
    public function forQuarter(Profile $profile, int $year, int $quarter): array
    {
        $segments = $this->segmentsByQuarter($profile, $year, $quarter);
        $rules = TaxRule::query()->whereKey(array_keys($this->ruleIds($segments)))->get()->keyBy('id');

        $ytd = $this->mergeSegments($segments, fn (int $q) => $q <= $quarter);
        $previous = $this->mergeSegments($segments, fn (int $q) => $q < $quarter);
        $current = $this->mergeSegments($segments, fn (int $q) => $q === $quarter);

        $rule = $this->rules->find(CarbonImmutable::create($year, $quarter * 3)->endOfMonth())
            ?? TaxRule::query()->orderByDesc('effective_from')->first();

        $ytdIncome = $this->income($ytd);
        $cap = Decimal::of($rule?->income_cap_uah);
        $excess = Decimal::max($ytdIncome->minus($cap), 0);

        return [
            'year' => $year,
            'quarter' => $quarter,
            'quarter_income' => Decimal::money($this->income($current)),
            'ytd_income' => Decimal::money($ytdIncome),
            'previous_ytd_income' => Decimal::money($this->income($previous)),
            'payments_count' => array_sum(array_column($current, 'count')),
            'single_tax' => $this->tax($ytd, $previous, $current, $rules, 'single_tax_rate', 'ep_hints', $rule),
            'military_levy' => $this->tax($ytd, $previous, $current, $rules, 'military_levy_rate', 'vz_hints', $rule),
            'esv' => $this->esv($profile, $year, $quarter, $rule),
            'cap' => [
                'income_cap' => Decimal::money($cap),
                'ytd_income' => Decimal::money($ytdIncome),
                'percent' => Decimal::percent($ytdIncome, $cap),
                'excess' => Decimal::money($excess),
                'excess_tax' => Decimal::money(Decimal::times($excess, $rule?->excess_rate)),
            ],
        ];
    }

    /**
     * Get the income of each month of the profile's year: receipts less refunds, in UAH, with the per-receipt hints.
     *
     * @param Profile $profile
     * @param int $year
     *
     * @return array<int, array{income: string, single_tax_hints: string, military_levy_hints: string, count: int}>
     *                                                                                                              Keyed by the month, 1 to 12.
     */
    public function incomeByMonth(Profile $profile, int $year): array
    {
        $months = array_fill_keys(range(1, 12), [
            'income' => Decimal::zero(),
            'single_tax_hints' => Decimal::zero(),
            'military_levy_hints' => Decimal::zero(),
            'count' => 0,
        ]);

        $payments = Payment::query()
            ->where('profile_id', $profile->getKey())
            ->where('period_year', $year)
            ->groupBy('period_month')
            ->selectRaw('period_month, SUM(uah_amount) as income, SUM(single_tax_uah) as ep_hints')
            ->selectRaw('SUM(military_levy_uah) as vz_hints, COUNT(*) as count')
            ->get();

        foreach ($payments as $row) {
            $month = &$months[(int) $row->period_month];
            $month['income'] = $month['income']->plus(Decimal::of((string) $row->income));
            $month['single_tax_hints'] = $month['single_tax_hints']->plus(Decimal::of((string) $row->ep_hints));
            $month['military_levy_hints'] = $month['military_levy_hints']->plus(Decimal::of((string) $row->vz_hints));
            $month['count'] += (int) $row->count;
            unset($month);
        }

        $refunds = Refund::query()
            ->where('profile_id', $profile->getKey())
            ->where('period_year', $year)
            ->with('taxRule')
            ->get(['id', 'period_month', 'tax_rule_id', 'uah_amount']);

        foreach ($refunds as $refund) {
            $month = &$months[(int) $refund->period_month];
            $month['income'] = $month['income']->minus(Decimal::of($refund->uah_amount));
            $month['single_tax_hints'] = $month['single_tax_hints']->minus(Decimal::times($refund->uah_amount, $refund->taxRule->single_tax_rate));
            $month['military_levy_hints'] = $month['military_levy_hints']->minus(Decimal::times($refund->uah_amount, $refund->taxRule->military_levy_rate));
            unset($month);
        }

        return array_map(fn (array $month) => [
            'income' => Decimal::money($month['income']),
            'single_tax_hints' => Decimal::money($month['single_tax_hints']),
            'military_levy_hints' => Decimal::money($month['military_levy_hints']),
            'count' => $month['count'],
        ], $months);
    }

    /**
     * Get the income of the profile's year up to the quarter, by quarter and tax rule: receipts less refunds, the
     * sums of the per-receipt hints, and the counts of receipts.
     *
     * @param Profile $profile
     * @param int $year
     * @param int $quarter
     *
     * @return array<int, array<int, array{income: BigDecimal, ep_hints: BigDecimal, vz_hints: BigDecimal, refunds: BigDecimal, count: int}>>
     */
    protected function segmentsByQuarter(Profile $profile, int $year, int $quarter): array
    {
        $segments = [];
        $empty = fn () => [
            'income' => Decimal::zero(),
            'ep_hints' => Decimal::zero(),
            'vz_hints' => Decimal::zero(),
            'refunds' => Decimal::zero(),
            'count' => 0,
        ];

        $payments = Payment::query()
            ->where('profile_id', $profile->getKey())
            ->yearToQuarter($year, $quarter)
            ->groupBy('period_quarter', 'tax_rule_id')
            ->selectRaw('period_quarter, tax_rule_id, SUM(uah_amount) as income, SUM(single_tax_uah) as ep_hints')
            ->selectRaw('SUM(military_levy_uah) as vz_hints, COUNT(*) as count')
            ->get();

        foreach ($payments as $row) {
            $segment = $segments[(int) $row->period_quarter][(int) $row->tax_rule_id] ?? $empty();
            $segment['income'] = $segment['income']->plus(Decimal::of((string) $row->income));
            $segment['ep_hints'] = $segment['ep_hints']->plus(Decimal::of((string) $row->ep_hints));
            $segment['vz_hints'] = $segment['vz_hints']->plus(Decimal::of((string) $row->vz_hints));
            $segment['count'] += (int) $row->count;
            $segments[(int) $row->period_quarter][(int) $row->tax_rule_id] = $segment;
        }

        $refunds = Refund::query()
            ->where('profile_id', $profile->getKey())
            ->where('period_year', $year)
            ->where('period_quarter', '<=', $quarter)
            ->with('taxRule')
            ->get(['id', 'period_quarter', 'tax_rule_id', 'uah_amount']);

        // A refund takes back the hints of its own amount, each rounded as a receipt's are
        foreach ($refunds as $refund) {
            $segment = $segments[(int) $refund->period_quarter][(int) $refund->tax_rule_id] ?? $empty();
            $segment['income'] = $segment['income']->minus(Decimal::of($refund->uah_amount));
            $segment['refunds'] = $segment['refunds']->plus(Decimal::of($refund->uah_amount));
            $segment['ep_hints'] = $segment['ep_hints']->minus(Decimal::times($refund->uah_amount, $refund->taxRule->single_tax_rate));
            $segment['vz_hints'] = $segment['vz_hints']->minus(Decimal::times($refund->uah_amount, $refund->taxRule->military_levy_rate));
            $segments[(int) $refund->period_quarter][(int) $refund->tax_rule_id] = $segment;
        }

        return $segments;
    }

    /**
     * Get ids of the tax rules of the segments, as keys.
     *
     * @param array<int, array<int, array>> $segments
     *
     * @return array<int, true>
     */
    protected function ruleIds(array $segments): array
    {
        $ids = [];

        foreach ($segments as $byRule) {
            foreach (array_keys($byRule) as $ruleId) {
                $ids[$ruleId] = true;
            }
        }

        return $ids;
    }

    /**
     * Merge the segments of the quarters, which the filter accepts, by tax rule.
     *
     * @param array<int, array<int, array>> $segments
     * @param callable(int): bool $filter
     *
     * @return array<int, array{income: BigDecimal, ep_hints: BigDecimal, vz_hints: BigDecimal, refunds: BigDecimal, count: int}>
     */
    protected function mergeSegments(array $segments, callable $filter): array
    {
        $merged = [];

        foreach ($segments as $quarter => $byRule) {
            if (!$filter((int) $quarter)) {
                continue;
            }

            foreach ($byRule as $ruleId => $segment) {
                $into = $merged[$ruleId] ?? null;

                $merged[$ruleId] = $into === null ? $segment : [
                    'income' => $into['income']->plus($segment['income']),
                    'ep_hints' => $into['ep_hints']->plus($segment['ep_hints']),
                    'vz_hints' => $into['vz_hints']->plus($segment['vz_hints']),
                    'refunds' => $into['refunds']->plus($segment['refunds']),
                    'count' => $into['count'] + $segment['count'],
                ];
            }
        }

        return $merged;
    }

    /**
     * Get the income of the segments.
     *
     * @param array<int, array{income: BigDecimal}> $segments
     *
     * @return BigDecimal
     */
    protected function income(array $segments): BigDecimal
    {
        return Decimal::sum(array_column($segments, 'income'));
    }

    /**
     * Get the liability of the segments: ROUND_HALF_UP(Σ segment income × the segment rule's rate, 2).
     *
     * @param array<int, array{income: BigDecimal}> $segments
     * @param Collection<int, TaxRule> $rules
     * @param string $rateColumn
     *
     * @return BigDecimal
     */
    protected function liability(array $segments, $rules, string $rateColumn): BigDecimal
    {
        $sum = Decimal::zero();

        foreach ($segments as $ruleId => $segment) {
            $sum = $sum->plus($segment['income']->multipliedBy(Decimal::of($rules->get($ruleId)?->$rateColumn)));
        }

        return Decimal::round($sum);
    }

    /**
     * Get the figures of one tax of the quarter: year-to-date, the previous quarter's year-to-date, the owed
     * difference, and the two cross-checks with their deltas (owed − check).
     *
     * @param array $ytd
     * @param array $previous
     * @param array $current
     * @param Collection<int, TaxRule> $rules
     * @param string $rateColumn
     * @param string $hintsKey
     * @param TaxRule|null $rule
     *
     * @return array<string, string>
     */
    protected function tax(
        array $ytd,
        array $previous,
        array $current,
        $rules,
        string $rateColumn,
        string $hintsKey,
        ?TaxRule $rule
    ): array {
        $ytdLiability = $this->liability($ytd, $rules, $rateColumn);
        $previousLiability = $this->liability($previous, $rules, $rateColumn);
        $owed = $ytdLiability->minus($previousLiability);
        $standalone = $this->liability($current, $rules, $rateColumn);

        $hints = Decimal::sum(array_column($current, $hintsKey));

        return [
            'rate' => Decimal::rate($rule?->$rateColumn),
            'ytd' => Decimal::money($ytdLiability),
            'previous_ytd' => Decimal::money($previousLiability),
            'owed' => Decimal::money($owed),
            'standalone' => Decimal::money($standalone),
            'standalone_delta' => Decimal::money($owed->minus($standalone)),
            'hints_sum' => Decimal::money($hints),
            'hints_delta' => Decimal::money($owed->minus($hints)),
        ];
    }

    /**
     * Get the ЄСВ of the quarter and of the year up to it: the sums of its due months.
     *
     * @param Profile $profile
     * @param int $year
     * @param int $quarter
     * @param TaxRule|null $rule
     *
     * @return array{monthly: string, months: int, owed: string, ytd: string}
     */
    protected function esv(Profile $profile, int $year, int $quarter, ?TaxRule $rule): array
    {
        $due = EsvObligation::query()
            ->where('profile_id', $profile->getKey())
            ->where('year', $year)
            ->where('month', '<=', $quarter * 3)
            ->where('status', EsvStatus::Due->value)
            ->get(['month', 'amount_due']);

        $inQuarter = $due->filter(fn (EsvObligation $month) => $month->month > ($quarter - 1) * 3);

        return [
            'monthly' => Decimal::money($rule?->esv_monthly),
            'months' => $inQuarter->count(),
            'owed' => Decimal::money(Decimal::sum($inQuarter->pluck('amount_due'))),
            'ytd' => Decimal::money(Decimal::sum($due->pluck('amount_due'))),
        ];
    }
}
