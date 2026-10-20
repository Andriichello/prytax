<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use PryTax\Enums\InvoiceSource;
use PryTax\Enums\InvoiceStatus;
use PryTax\Models\Account;
use PryTax\Models\Client;
use PryTax\Models\Invoice;
use PryTax\Models\Profile;

/**
 * Class InvoiceFactory.
 *
 * Draft invoices of a new profile, unless one is given, with a client and a receiving account of that profile. Tests,
 * which need frozen invoices, freeze them with InvoiceFreezer, so they get their numbers and snapshots.
 *
 * @extends Factory<Invoice>
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Database\Factories
 */
class InvoiceFactory extends Factory
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
            'client_id' => fn (array $attributes) => Client::factory()->create(['profile_id' => $attributes['profile_id']]),
            'receiving_account_id' => fn (array $attributes) => Account::factory()
                ->create(['profile_id' => $attributes['profile_id'], 'currency' => $attributes['currency']]),
            'source' => InvoiceSource::Generated,
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-14',
            'issued_at' => '2026-10-14',
            'due_at' => '2026-10-28',
            'currency' => 'USD',
            'subtotal' => '3892.50',
            'adjustment_amount' => '0.00',
            'total' => '3892.50',
            'status' => InvoiceStatus::Draft,
        ];
    }
}
