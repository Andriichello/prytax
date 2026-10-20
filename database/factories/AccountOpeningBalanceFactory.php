<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use PryTax\Models\Account;
use PryTax\Models\AccountOpeningBalance;
use PryTax\Models\Profile;

/**
 * Class AccountOpeningBalanceFactory.
 *
 * Opening balances of a new profile's account: 1 000.00 at the start of 2026 by default.
 *
 * @extends Factory<AccountOpeningBalance>
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Database\Factories
 */
class AccountOpeningBalanceFactory extends Factory
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
            'account_id' => fn (array $attributes) => Account::factory()->create(['profile_id' => $attributes['profile_id']]),
            'as_of' => '2026-01-01',
            'amount' => '1000.00',
        ];
    }
}
