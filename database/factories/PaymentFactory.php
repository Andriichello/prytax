<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use PryTax\Enums\PaymentKind;
use PryTax\Helpers\Decimal;
use PryTax\Models\Account;
use PryTax\Models\Payment;
use PryTax\Models\Profile;
use PryTax\Models\TaxRule;

/**
 * Class PaymentFactory.
 *
 * Receipts of other income of a new profile, unless one is given, with a consistent snapshot: USD 1 000.00 at
 * 41.0000 on 2026-10-05. Tests of receipts of invoices record them with PaymentRecorder, which computes the snapshot.
 *
 * @extends Factory<Payment>
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Database\Factories
 */
class PaymentFactory extends Factory
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
            'kind' => PaymentKind::Other,
            'currency' => 'USD',
            'amount' => '1000.00',
            'received_to_account_id' => fn (array $attributes) => Account::factory()
                ->create(['profile_id' => $attributes['profile_id'], 'currency' => $attributes['currency']]),
            'received_amount' => fn (array $attributes) => $attributes['amount'],
            'received_currency' => fn (array $attributes) => $attributes['currency'],
            'received_at' => '2026-10-05',
            'nbu_rate' => fn (array $attributes) => $attributes['currency'] === 'UAH' ? '1.0000' : '41.0000',
            'nbu_rate_date' => fn (array $attributes) => $attributes['received_at'],
            // The snapshot follows the attributes, as PaymentSnapshotter computes it
            'uah_amount' => fn (array $attributes) => Decimal::money(Decimal::times($attributes['amount'], $attributes['nbu_rate'])),
            'tax_rule_id' => fn (array $attributes) => TaxRule::query()->effectiveOn($attributes['received_at'])->value('id'),
            'single_tax_uah' => fn (array $attributes) => Decimal::money(
                Decimal::times($attributes['uah_amount'], TaxRule::query()->find($attributes['tax_rule_id'])?->single_tax_rate)
            ),
            'military_levy_uah' => fn (array $attributes) => Decimal::money(
                Decimal::times($attributes['uah_amount'], TaxRule::query()->find($attributes['tax_rule_id'])?->military_levy_rate)
            ),
        ];
    }
}
