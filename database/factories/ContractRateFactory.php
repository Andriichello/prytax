<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use PryTax\Models\Contract;
use PryTax\Models\ContractRate;

/**
 * Class ContractRateFactory.
 *
 * Hourly rates of a new contract: its default rate of 45.00 from its start by default.
 *
 * @extends Factory<ContractRate>
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Database\Factories
 */
class ContractRateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'contract_id' => Contract::factory(),
            'effective_from' => '2023-04-13',
            'hourly_rate' => '45.00',
        ];
    }
}
