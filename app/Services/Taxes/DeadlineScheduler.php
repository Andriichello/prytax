<?php

namespace PryTax\Services\Taxes;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use PryTax\Models\Profile;
use PryTax\Models\TaxPeriod;

/**
 * Class DeadlineScheduler.
 *
 * Creates the tax periods (quarters) of profiles with their deadlines (design.md §8.3): the declaration is due 40
 * calendar days after the quarter (п. 49.18.2), ЄП and ВЗ 10 days after the statutory declaration date, counted from
 * it before its roll-over (п. 295.3), and ЄСВ by the profile's day of the month after the quarter, the 19th by
 * default. Each date then rolls forward to a business day. Q1 2026: 10 May is a Sunday, so the declaration is due
 * 11 May, while ЄП and ВЗ stay due 20 May.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Services\Taxes
 */
class DeadlineScheduler
{
    /**
     * DeadlineScheduler constructor.
     *
     * @param BusinessDayCalendar $calendar
     */
    public function __construct(
        protected BusinessDayCalendar $calendar
    ) {}

    /**
     * Get the deadlines of the quarter.
     *
     * @param int $year
     * @param int $quarter
     * @param int $esvDueDay
     *
     * @return array{declaration_due_at: CarbonImmutable, tax_due_at: CarbonImmutable, esv_due_at: CarbonImmutable}
     */
    public function deadlines(int $year, int $quarter, int $esvDueDay = 19): array
    {
        $end = CarbonImmutable::create($year, $quarter * 3)->endOfMonth()->startOfDay();
        $declaration = $end->addDays(40);

        return [
            'declaration_due_at' => $this->calendar->rollForward($declaration),
            'tax_due_at' => $this->calendar->rollForward($declaration->addDays(10)),
            'esv_due_at' => $this->calendar->rollForward($end->addDay()->addDays($esvDueDay - 1)),
        ];
    }

    /**
     * Get the profile's tax period of the quarter, creating it with its deadlines, when it doesn't exist.
     *
     * @param Profile $profile
     * @param int $year
     * @param int $quarter
     *
     * @return TaxPeriod
     */
    public function ensure(Profile $profile, int $year, int $quarter): TaxPeriod
    {
        $existing = TaxPeriod::query()
            ->where('profile_id', $profile->getKey())
            ->where('year', $year)
            ->where('quarter', $quarter)
            ->first();

        if ($existing) {
            return $existing;
        }

        $deadlines = $this->deadlines($year, $quarter, (int) $profile->setting('taxes.esv_due_day'));

        $period = new TaxPeriod([
            'year' => $year,
            'quarter' => $quarter,
            'is_annual' => $quarter === 4,
            'declaration_due_at' => $deadlines['declaration_due_at']->toDateString(),
            'tax_due_at' => $deadlines['tax_due_at']->toDateString(),
            'esv_due_at' => $deadlines['esv_due_at']->toDateString(),
        ]);
        $period->profile_id = $profile->getKey();
        $period->save();

        return $period;
    }

    /**
     * Create the tax periods of the profile's current and next quarter, and of every earlier quarter of the year.
     *
     * @param Profile $profile
     * @param CarbonInterface|null $today
     *
     * @return void
     */
    public function ensureCurrentAndNext(Profile $profile, ?CarbonInterface $today = null): void
    {
        $today = CarbonImmutable::parse(($today ?? now())->toDateString());

        for ($quarter = 1; $quarter <= $today->quarter; $quarter++) {
            $this->ensure($profile, $today->year, $quarter);
        }

        $next = $today->addQuarterNoOverflow();
        $this->ensure($profile, $next->year, $next->quarter);
    }
}
