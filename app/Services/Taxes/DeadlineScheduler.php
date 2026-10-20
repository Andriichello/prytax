<?php

namespace PryTax\Services\Taxes;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use PryTax\Enums\DeclarationStatus;
use PryTax\Models\Profile;
use PryTax\Models\TaxPeriod;

/**
 * Class DeadlineScheduler.
 *
 * Creates the tax periods (quarters) of profiles with their deadlines (design.md §8.3): the declaration is due 40
 * calendar days after the quarter (п. 49.18.2), ЄП and ВЗ 10 days after the statutory declaration date, counted from
 * it before its roll-over (п. 295.3), and ЄСВ by the day of the month after the quarter, which the profile set, or
 * else its tax rule's (the 19th). Each date then rolls forward to a business day. Q1 2026: 10 May is a Sunday, so the
 * declaration is due 11 May, while ЄП and ВЗ stay due 20 May.
 *
 * Periods are created for the tracked quarters only (TaxTracking); an untracked quarter gets a period, which isn't
 * saved, so reading it creates nothing. A saved period's deadlines follow a change of the ЄСВ day or of the holidays.
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
     * @param TaxTracking $tracking
     * @param TaxRuleResolver $rules
     */
    public function __construct(
        protected BusinessDayCalendar $calendar,
        protected TaxTracking $tracking,
        protected TaxRuleResolver $rules
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
     * Get the profile's tax period of the quarter: the saved one, with its deadlines brought up to date, or a new one,
     * which is saved when the quarter is tracked.
     *
     * @param Profile $profile
     * @param int $year
     * @param int $quarter
     *
     * @return TaxPeriod
     */
    public function ensure(Profile $profile, int $year, int $quarter): TaxPeriod
    {
        $deadlines = array_map(
            fn (CarbonImmutable $date) => $date->toDateString(),
            $this->deadlines($year, $quarter, $this->esvDueDay($profile, $year, $quarter))
        );

        $period = TaxPeriod::query()
            ->where('profile_id', $profile->getKey())
            ->where('year', $year)
            ->where('quarter', $quarter)
            ->first();

        if ($period) {
            $period->fill($deadlines);

            if ($period->isDirty()) {
                $period->save();
            }

            return $period;
        }

        $period = new TaxPeriod([
            'year' => $year,
            'quarter' => $quarter,
            'is_annual' => $quarter === 4,
            ...$deadlines,
        ]);
        $period->profile_id = $profile->getKey();
        $period->declaration_status = DeclarationStatus::None;

        if ($this->tracking->tracks($profile, $year, $quarter)) {
            $period->save();
        }

        return $period;
    }

    /**
     * Create the tax periods of the profile's tracked quarters up to the next one, and bring their deadlines up to
     * date.
     *
     * @param Profile $profile
     * @param CarbonInterface|null $today
     *
     * @return void
     */
    public function ensureCurrentAndNext(Profile $profile, ?CarbonInterface $today = null): void
    {
        foreach ($this->tracking->quarters($profile, $today) as [$year, $quarter]) {
            $this->ensure($profile, $year, $quarter);
        }
    }

    /**
     * Get the day of the month after a quarter, by which its ЄСВ is due: the profile's, or else its tax rule's.
     *
     * @param Profile $profile
     * @param int $year
     * @param int $quarter
     *
     * @return int
     */
    protected function esvDueDay(Profile $profile, int $year, int $quarter): int
    {
        $rule = $this->rules->find(CarbonImmutable::create($year, $quarter * 3)->endOfMonth());

        return (int) ($profile->setting('taxes.esv_due_day') ?? $rule?->esv_due_day ?? 19);
    }
}
