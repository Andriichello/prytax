<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use PryTax\Models\Client;
use PryTax\Models\Contract;

/**
 * Class ContractFactory.
 *
 * Contracts of a new client, unless one is given, in the profile of the client.
 *
 * @extends Factory<Contract>
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Database\Factories
 */
class ContractFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'profile_id' => fn (array $attributes) => Client::query()->withoutGlobalScopes()
                ->whereKey($attributes['client_id'])
                ->value('profile_id'),
            'name' => 'Service Agreement',
            'started_at' => '2023-04-13',
            'currency' => 'USD',
            'payment_terms_days' => 14,
            'description_template' => 'IT services in accordance to the {name} dated {started_at:F j, Y}',
            'is_active' => true,
        ];
    }
}
