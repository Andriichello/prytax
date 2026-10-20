<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use PryTax\Enums\EsvStatus;
use PryTax\Models\EsvObligation;
use PryTax\Models\Profile;
use PryTax\Models\TaxRule;

/**
 * Class EsvObligationFactory.
 *
 * Months of ЄСВ of a new profile: January 2026, due at the month's rule, by default.
 *
 * @extends Factory<EsvObligation>
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Database\Factories
 */
class EsvObligationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'profile_id' => Profile::factory(),
            'year' => 2026,
            'month' => 1,
            'tax_rule_id' => fn (array $attributes) => TaxRule::query()
                ->effectiveOn(sprintf('%d-%02d-01', $attributes['year'], $attributes['month']))
                ->value('id'),
            'amount_due' => fn (array $attributes) => TaxRule::query()->find($attributes['tax_rule_id'])?->esv_monthly,
            'status' => EsvStatus::Due,
        ];
    }

    /**
     * A month, in which ЄСВ isn't owed.
     *
     * @return static
     */
    public function exempt(): static
    {
        return $this->state(fn () => ['status' => EsvStatus::Exempt]);
    }
}
