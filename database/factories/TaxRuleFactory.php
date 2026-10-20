<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use PryTax\Models\TaxRule;

/**
 * Class TaxRuleFactory.
 *
 * Tax rules of a year, which the migrations don't add: 2030 by default, with the rates of 3 група.
 *
 * @extends Factory<TaxRule>
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Database\Factories
 */
class TaxRuleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'effective_from' => '2030-01-01',
            'effective_to' => '2030-12-31',
            'single_tax_rate' => '0.0500',
            'military_levy_rate' => '0.0100',
            'excess_rate' => '0.1500',
            'min_wage' => '10000.00',
            'esv_monthly' => '2200.00',
            'esv_mandatory' => true,
            'esv_due_day' => 19,
            'income_cap_uah' => '11670000.00',
        ];
    }
}
