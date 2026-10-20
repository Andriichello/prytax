<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use PryTax\Models\Account;
use PryTax\Models\AccountTransfer;
use PryTax\Models\Profile;

/**
 * Class AccountTransferFactory.
 *
 * Transfers between two accounts of a new profile: $1 000 from Payoneer to Wise with a $15 fee by default. Their
 * allocations are created apart, as TransferAllocationFactory does.
 *
 * @extends Factory<AccountTransfer>
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Database\Factories
 */
class AccountTransferFactory extends Factory
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
            'from_account_id' => fn (array $attributes) => Account::factory()->create(['profile_id' => $attributes['profile_id'], 'name' => 'Payoneer USD']),
            'to_account_id' => fn (array $attributes) => Account::factory()->create(['profile_id' => $attributes['profile_id']]),
            'amount_sent' => '1000.00',
            'currency_sent' => 'USD',
            'amount_received' => '985.00',
            'currency_received' => 'USD',
            'fee_amount' => '15.00',
            'fee_currency' => 'USD',
            'received_at' => '2026-10-10',
        ];
    }
}
