<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use PryTax\Enums\ItemUnit;
use PryTax\Models\Invoice;
use PryTax\Models\InvoiceItem;

/**
 * Class InvoiceItemFactory.
 *
 * Items of a new draft invoice: the mock's 86.50 hours at 45.00 by default.
 *
 * @extends Factory<InvoiceItem>
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Database\Factories
 */
class InvoiceItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory(),
            'position' => 1,
            'description' => 'IT services in accordance to the Service Agreement dated April 13, 2023',
            'unit' => ItemUnit::Hour,
            'quantity' => '86.50',
            'unit_price' => '45.00',
            'amount' => '3892.50',
        ];
    }
}
