<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use PryTax\Enums\MovementCategory;
use PryTax\Enums\MovementDirection;
use PryTax\Models\Account;
use PryTax\Models\AccountMovement;
use PryTax\Models\Profile;

/**
 * Class AccountMovementFactory.
 *
 * Movements of an account, which aren't income: a bank fee of a new profile's account by default.
 *
 * @extends Factory<AccountMovement>
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Database\Factories
 */
class AccountMovementFactory extends Factory
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
            'direction' => MovementDirection::Out,
            'amount' => '25.00',
            'booked_at' => '2026-10-10',
            'category' => MovementCategory::BankFee,
        ];
    }
}
