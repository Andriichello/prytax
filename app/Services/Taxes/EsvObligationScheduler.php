<?php

namespace PryTax\Services\Taxes;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use PryTax\Enums\EsvStatus;
use PryTax\Models\EsvObligation;
use PryTax\Models\Profile;

/**
 * Class EsvObligationScheduler.
 *
 * Creates the months of ЄСВ of a profile from the tax rules, independently of income (design.md §8.3): 22 % of the
 * minimum wage each month, due when ЄСВ is mandatory, and exempt in the voluntary window of 2022-03 … 2024-12. Only
 * the tracked months are created (TaxTracking: from the registration or the first record to the closure), a month
 * without a tax rule isn't (a year, whose rules aren't added yet, owes nothing rather than last year's amounts), and
 * months, which a change of the registration or the closure left outside, are removed.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Services\Taxes
 */
class EsvObligationScheduler
{
    /**
     * EsvObligationScheduler constructor.
     *
     * @param TaxRuleResolver $rules
     * @param TaxTracking $tracking
     */
    public function __construct(
        protected TaxRuleResolver $rules,
        protected TaxTracking $tracking
    ) {}

    /**
     * Create the months of ЄСВ of every tracked year of the profile, up to the year of the next quarter.
     *
     * @param Profile $profile
     * @param CarbonInterface|null $today
     *
     * @return void
     */
    public function ensureTracked(Profile $profile, ?CarbonInterface $today = null): void
    {
        $years = array_values(array_unique(array_column($this->tracking->quarters($profile, $today), 0)));

        // Months of years, which are no longer tracked, such as after the registration moved, go
        EsvObligation::query()
            ->where('profile_id', $profile->getKey())
            ->whereNotIn('year', $years ?: [0])
            ->get()
            ->each->delete();

        foreach ($years as $year) {
            $this->ensureYear($profile, $year);
        }
    }

    /**
     * Get the profile's months of ЄСВ of the year, creating the missing ones.
     *
     * @param Profile $profile
     * @param int $year
     *
     * @return Collection<int, EsvObligation> Keyed by the month.
     */
    public function ensureYear(Profile $profile, int $year): Collection
    {
        $months = $this->tracking->months($profile, $year);

        // A base collection: an Eloquent one's except() takes the models' ids, not the months
        $existing = EsvObligation::query()
            ->where('profile_id', $profile->getKey())
            ->where('year', $year)
            ->get()
            ->keyBy('month')
            ->toBase();

        foreach ($existing->except($months) as $month => $outside) {
            $outside->delete();
            $existing->forget($month);
        }

        foreach ($months as $month) {
            if ($existing->has($month)) {
                continue;
            }

            $rule = $this->rules->find(CarbonImmutable::create($year, $month));

            if (!$rule) {
                continue;
            }

            $obligation = new EsvObligation;
            $obligation->forceFill([
                'profile_id' => $profile->getKey(),
                'year' => $year,
                'month' => $month,
                'tax_rule_id' => $rule->getKey(),
                'amount_due' => $rule->esv_monthly,
                'status' => $rule->esv_mandatory ? EsvStatus::Due : EsvStatus::Exempt,
            ])->save();

            $existing->put($month, $obligation);
        }

        return $existing->sortKeys();
    }
}
