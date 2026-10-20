<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use PryTax\Enums\TaxType;
use PryTax\Models\Profile;
use PryTax\Models\TaxOpeningBalance;

/**
 * Class TaxOpeningBalanceFactory.
 *
 * Opening balances of a new profile's taxes: ЄП overpaid by 51.00 at the start of 2026 by default.
 *
 * @extends Factory<TaxOpeningBalance>
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Database\Factories
 */
class TaxOpeningBalanceFactory extends Factory
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
            'tax_type' => TaxType::SingleTax,
            'as_of' => '2026-01-01',
            'amount' => '51.00',
        ];
    }
}
