<?php

namespace PryTax\Services\Taxes;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use PryTax\Models\Holiday;

/**
 * Class BusinessDayCalendar.
 *
 * Business days for the roll-over of deadlines: a declaration due on a weekend or a holiday is due on the next
 * business day (п. 49.20), a payment on the next banking day (п. 57.1). While martial law lasts, holidays are working
 * days (Закон 2136-IX), so the `holidays` table is empty and only weekends roll over.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Services\Taxes
 */
class BusinessDayCalendar
{
    /**
     * Dates of the holidays, which were loaded.
     *
     * @var array<string, true>|null
     */
    protected ?array $holidays = null;

    /**
     * Determine if the date is a business day.
     *
     * @param CarbonInterface $date
     *
     * @return bool
     */
    public function isBusinessDay(CarbonInterface $date): bool
    {
        $this->holidays ??= Holiday::query()
            ->pluck('date')
            ->mapWithKeys(fn ($date) => [CarbonImmutable::parse($date)->toDateString() => true])
            ->all();

        return !$date->isWeekend() && !isset($this->holidays[$date->toDateString()]);
    }

    /**
     * Get the date, or the next business day after it, when it isn't one.
     *
     * @param CarbonInterface $date
     *
     * @return CarbonImmutable
     */
    public function rollForward(CarbonInterface $date): CarbonImmutable
    {
        $day = CarbonImmutable::parse($date->toDateString());

        while (!$this->isBusinessDay($day)) {
            $day = $day->addDay();
        }

        return $day;
    }
}
