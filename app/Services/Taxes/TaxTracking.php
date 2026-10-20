<?php

namespace PryTax\Services\Taxes;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use PryTax\Models\Payment;
use PryTax\Models\Profile;
use PryTax\Models\Refund;
use PryTax\Models\TaxOpeningBalance;
use PryTax\Models\TaxPayment;

/**
 * Class TaxTracking.
 *
 * The quarters, in which the app tracks a profile's tax obligations (tax periods with their deadlines, and the months
 * of ЄСВ): from where the profile's records start, but never before its registration, until its closure. Records
 * start at the earliest opening balance of a tax, which says the balances before it are settled, or else at the
 * first of January of the year of the first record (a receipt, a refund or a tax payment), or of the profile's
 * creation, as declarations are cumulative over a year. So a profile, which started using the app this year, owes
 * nothing for the years it never entered, a newly registered ФОП has no deadlines before its registration, and
 * reading a year never creates obligations outside of this range.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Services\Taxes
 */
class TaxTracking
{
    /**
     * First days of the tracking, which were computed, by id of the profile.
     *
     * @var array<int, CarbonImmutable>
     */
    protected array $starts = [];

    /**
     * Get the first day of the tracking: the registration, or the first day of the quarter of the first record,
     * whichever is later.
     *
     * @param Profile $profile
     *
     * @return CarbonImmutable
     */
    public function start(Profile $profile): CarbonImmutable
    {
        return $this->starts[$profile->getKey()] ??= $this->computeStart($profile);
    }

    /**
     * Get the last day of the tracking: the profile's closure, or null while it is open.
     *
     * @param Profile $profile
     *
     * @return CarbonImmutable|null
     */
    public function end(Profile $profile): ?CarbonImmutable
    {
        return $profile->closed_at === null ? null : CarbonImmutable::parse($profile->closed_at->toDateString());
    }

    /**
     * Determine if the quarter is tracked: it ends after the tracking starts, it starts before the profile closed,
     * and it isn't later than the quarter after today's.
     *
     * @param Profile $profile
     * @param int $year
     * @param int $quarter
     * @param CarbonInterface|null $today
     *
     * @return bool
     */
    public function tracks(Profile $profile, int $year, int $quarter, ?CarbonInterface $today = null): bool
    {
        $first = CarbonImmutable::create($year, $quarter * 3 - 2);
        $last = $first->endOfQuarter()->startOfDay();
        $horizon = CarbonImmutable::parse(($today ?? now())->toDateString())->addQuarterNoOverflow()->endOfQuarter()->startOfDay();
        $end = $this->end($profile);

        return $last->gte($this->start($profile))
            && ($end === null || $first->lte($end))
            && $last->lte($horizon);
    }

    /**
     * Get the tracked quarters up to the quarter after today's, the earliest first.
     *
     * @param Profile $profile
     * @param CarbonInterface|null $today
     *
     * @return array<int, array{0: int, 1: int}> Pairs of a year and a quarter.
     */
    public function quarters(Profile $profile, ?CarbonInterface $today = null): array
    {
        $quarters = [];
        $quarter = $this->start($profile)->startOfQuarter();
        $horizon = CarbonImmutable::parse(($today ?? now())->toDateString())->addQuarterNoOverflow();

        while ($quarter->lte($horizon)) {
            if ($this->tracks($profile, $quarter->year, $quarter->quarter, $today)) {
                $quarters[] = [$quarter->year, $quarter->quarter];
            }

            $quarter = $quarter->addQuarterNoOverflow();
        }

        return $quarters;
    }

    /**
     * Get the tracked months of the year, in which ЄСВ is owed: from the start of the tracking (the month of the
     * registration, when that is later) to the month of the closure.
     *
     * @param Profile $profile
     * @param int $year
     *
     * @return array<int, int>
     */
    public function months(Profile $profile, int $year): array
    {
        $start = $this->start($profile);
        $end = $this->end($profile);

        $first = match (true) {
            $start->year > $year => 13,
            $start->year === $year => $start->month,
            default => 1,
        };

        $last = match (true) {
            $end === null || $end->year > $year => 12,
            $end->year === $year => $end->month,
            default => 0,
        };

        return $first <= $last ? range($first, $last) : [];
    }

    /**
     * Forget the computed first days, as after a record, which may move them, was saved.
     *
     * @return void
     */
    public function forget(): void
    {
        $this->starts = [];
    }

    /**
     * Compute the first day of the tracking.
     *
     * @param Profile $profile
     *
     * @return CarbonImmutable
     */
    protected function computeStart(Profile $profile): CarbonImmutable
    {
        $id = $profile->getKey();
        $opening = TaxOpeningBalance::query()->withoutGlobalScope(TaxOpeningBalance::PROFILE_SCOPE)->where('profile_id', $id)->min('as_of');

        if ($opening !== null) {
            $first = CarbonImmutable::parse($opening)->startOfQuarter();
        } else {
            $taxPaymentYear = TaxPayment::query()
                ->withoutGlobalScope(TaxPayment::PROFILE_SCOPE)
                ->where('profile_id', $id)
                ->min('period_year');

            $years = array_filter([
                Payment::query()->withoutGlobalScope(Payment::PROFILE_SCOPE)->where('profile_id', $id)->min('period_year'),
                Refund::query()->withoutGlobalScope(Refund::PROFILE_SCOPE)->where('profile_id', $id)->min('period_year'),
                $taxPaymentYear,
                ($profile->created_at ?? now())->year,
            ]);

            $first = CarbonImmutable::create((int) min($years));
        }

        $registered = $profile->registered_at === null ? null : CarbonImmutable::parse($profile->registered_at->toDateString());

        return $registered !== null && $registered->gt($first) ? $registered : $first;
    }
}
