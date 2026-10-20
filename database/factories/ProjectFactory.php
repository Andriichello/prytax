<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use PryTax\Models\Client;
use PryTax\Models\Project;

/**
 * Class ProjectFactory.
 *
 * Projects of a new client: the mock's PROTO by default.
 *
 * @extends Factory<Project>
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Database\Factories
 */
class ProjectFactory extends Factory
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
            'code' => 'PROTO',
            'name' => 'Prototype',
            'is_active' => true,
        ];
    }
}
