<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use PryTax\Models\AccountTransfer;
use PryTax\Models\Payment;
use PryTax\Models\TransferAllocation;

/**
 * Class TransferAllocationFactory.
 *
 * Allocations of a new transfer: the whole amount sent, for a receipt on its source account, by default.
 *
 * @extends Factory<TransferAllocation>
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Database\Factories
 */
class TransferAllocationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'transfer_id' => AccountTransfer::factory(),
            'payment_id' => fn (array $attributes) => Payment::factory()->create([
                'profile_id' => AccountTransfer::query()->withoutGlobalScopes()->whereKey($attributes['transfer_id'])->value('profile_id'),
                'received_to_account_id' => AccountTransfer::query()->withoutGlobalScopes()->whereKey($attributes['transfer_id'])->value('from_account_id'),
            ]),
            'amount' => fn (array $attributes) => AccountTransfer::query()->withoutGlobalScopes()->whereKey($attributes['transfer_id'])->value('amount_sent'),
        ];
    }
}
