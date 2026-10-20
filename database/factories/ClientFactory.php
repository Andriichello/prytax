<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use PryTax\Models\Client;
use PryTax\Models\Profile;

/**
 * Class ClientFactory.
 *
 * Clients of a new profile, unless one is given.
 *
 * @extends Factory<Client>
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Database\Factories
 */
class ClientFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->company();

        return [
            'profile_id' => Profile::factory(),
            'name' => $name,
            'legal_name' => "$name LLC",
            'country' => 'US',
            'currency' => 'USD',
            'address' => ['lines' => [fake()->streetAddress()], 'city' => fake()->city(), 'zip' => fake()->postcode()],
            'email' => fake()->companyEmail(),
            'is_active' => true,
        ];
    }

    /**
     * A client, which was deactivated.
     *
     * @return static
     */
    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
