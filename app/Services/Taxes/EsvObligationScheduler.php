<?php

namespace PryTax\Services\Taxes;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use PryTax\Enums\EsvStatus;
use PryTax\Models\EsvObligation;
use PryTax\Models\Profile;

/**
 * Class EsvObligationScheduler.
 *
 * Creates the months of ЄСВ of a profile from the tax rules, independently of income (design.md §8.3): 22 % of the
 * minimum wage each month, due when ЄСВ is mandatory, and exempt in the voluntary window of 2022-03 … 2024-12. Months
 * before the profile's registration and after its closure aren't created.
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
     */
    public function __construct(
        protected TaxRuleResolver $rules
    ) {}

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
        $existing = EsvObligation::query()
            ->where('profile_id', $profile->getKey())
            ->where('year', $year)
            ->get()
            ->keyBy('month');

        foreach ($this->months($profile, $year) as $month) {
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

    /**
     * Get the months of the year, in which the profile owes ЄСВ: from its registration to its closure.
     *
     * @param Profile $profile
     * @param int $year
     *
     * @return array<int, int>
     */
    protected function months(Profile $profile, int $year): array
    {
        $first = 1;
        $last = 12;

        if ($profile->registered_at?->year === $year) {
            $first = $profile->registered_at->month;
        } elseif ($profile->registered_at?->year > $year) {
            return [];
        }

        if ($profile->closed_at?->year === $year) {
            $last = $profile->closed_at->month;
        } elseif ($profile->closed_at !== null && $profile->closed_at->year < $year) {
            return [];
        }

        return $first <= $last ? range($first, $last) : [];
    }
}
