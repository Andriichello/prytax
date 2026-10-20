<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use PryTax\Helpers\Decimal;
use PryTax\Models\Payment;
use PryTax\Models\Refund;
use PryTax\Models\TaxRule;

/**
 * Class RefundFactory.
 *
 * Refunds of a new receipt: $100 of it on 20.10.2026 at 42.0000, from the account it landed on, by default.
 *
 * @extends Factory<Refund>
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Database\Factories
 */
class RefundFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'payment_id' => Payment::factory(),
            'profile_id' => fn (array $attributes) => Payment::query()->withoutGlobalScopes()->whereKey($attributes['payment_id'])->value('profile_id'),
            'from_account_id' => fn (array $attributes) => Payment::query()->withoutGlobalScopes()->whereKey($attributes['payment_id'])->value('received_to_account_id'),
            'amount' => '100.00',
            'currency' => fn (array $attributes) => Payment::query()->withoutGlobalScopes()->whereKey($attributes['payment_id'])->value('currency'),
            'refunded_at' => '2026-10-20',
            'nbu_rate' => '42.0000',
            'nbu_rate_date' => fn (array $attributes) => $attributes['refunded_at'],
            'uah_amount' => fn (array $attributes) => Decimal::money(Decimal::times($attributes['amount'], $attributes['nbu_rate'])),
            'tax_rule_id' => fn (array $attributes) => TaxRule::query()->effectiveOn($attributes['refunded_at'])->value('id'),
        ];
    }
}
