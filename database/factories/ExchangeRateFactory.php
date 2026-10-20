<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use PryTax\Models\ExchangeRate;

/**
 * Class ExchangeRateFactory.
 *
 * Cached NBU rates: USD on the mock's receipt day by default.
 *
 * @extends Factory<ExchangeRate>
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Database\Factories
 */
class ExchangeRateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'currency' => 'USD',
            'rate_date' => '2026-10-05',
            'rate' => '42.2297',
            'fetched_at' => now(),
        ];
    }
}
