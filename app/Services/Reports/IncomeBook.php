<?php

namespace PryTax\Services\Reports;

use Carbon\CarbonImmutable;
use PryTax\Helpers\Decimal;
use PryTax\Models\Profile;
use PryTax\Services\Taxes\TaxLiabilityCalculator;

/**
 * Class IncomeBook.
 *
 * Книга доходів: the free-form income record of п. 296.1, the income of each month of a year with the running total
 * and the quarter subtotals. A month's income is the sum of its already rounded receipts, less the refunds of the
 * month.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Services\Reports
 */
class IncomeBook
{
    /**
     * IncomeBook constructor.
     *
     * @param TaxLiabilityCalculator $liabilities
     */
    public function __construct(
        protected TaxLiabilityCalculator $liabilities
    ) {}

    /**
     * Get the income book of the profile's year.
     *
     * @param Profile $profile
     * @param int $year
     * @param CarbonImmutable|null $today
     *
     * @return array{year: int, months: array<int, array<string, mixed>>, quarters: array<int, array<string, mixed>>, total: string}
     */
    public function forYear(Profile $profile, int $year, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $running = Decimal::zero();
        $months = [];
        $quarters = [];

        foreach ($this->liabilities->incomeByMonth($profile, $year) as $month => $income) {
            $running = $running->plus(Decimal::of($income['income']));
            $quarter = intdiv($month - 1, 3) + 1;

            $months[] = [
                'month' => $month,
                'quarter' => $quarter,
                'income' => $income['income'],
                'cumulative' => Decimal::money($running),
                'count' => $income['count'],
                'is_current' => $year === $today->year && $month === $today->month,
                'is_future' => CarbonImmutable::create($year, $month)->gt($today->startOfMonth()),
            ];

            $quarters[$quarter] = [
                'quarter' => $quarter,
                'income' => Decimal::money(Decimal::of($quarters[$quarter]['income'] ?? '0')->plus(Decimal::of($income['income']))),
                'cumulative' => Decimal::money($running),
            ];
        }

        return [
            'year' => $year,
            'months' => $months,
            'quarters' => array_values($quarters),
            'total' => Decimal::money($running),
        ];
    }
}
