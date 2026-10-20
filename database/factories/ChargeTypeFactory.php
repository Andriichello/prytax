<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use PryTax\Models\ChargeType;
use PryTax\Models\Client;

/**
 * Class ChargeTypeFactory.
 *
 * Charge types of a new client: Billable by default.
 *
 * @extends Factory<ChargeType>
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Database\Factories
 */
class ChargeTypeFactory extends Factory
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
            'profile_id' => fn (array $attributes) => Client::query()->withoutGlobalScopes()->whereKey($attributes['client_id'])->value('profile_id'),
            'name' => 'Billable',
            'is_active' => true,
        ];
    }
}
