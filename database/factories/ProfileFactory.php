<?php

namespace Database\Factories;

use Core\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use PryTax\Models\Profile;

/**
 * Class ProfileFactory.
 *
 * Profiles (ФОП) of a new organization, unless one is given.
 *
 * @extends Factory<Profile>
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Database\Factories
 */
class ProfileFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'full_name' => fake()->lastName() . ' ' . fake()->firstName() . ' ' . fake()->firstName(),
            'full_name_en' => fake()->name(),
            'tax_id' => fake()->unique()->numerify('##########'),
            'tax_id_kind' => 'rnokpp',
            'registered_at' => '2023-04-01',
            'address' => fake()->address(),
            'address_en' => fake()->address(),
            'email' => fake()->safeEmail(),
            'phone' => '+380' . fake()->numerify('#########'),
            'default_currency' => 'USD',
            'locale' => 'en',
            'is_active' => true,
            // Its taxes are tracked from the start of 2026 (TaxTracking), the year the tests' records are in
            'created_at' => '2026-01-01 09:00:00',
        ];
    }

    /**
     * A closed ФОП, which drops out of dashboards and deadlines.
     *
     * @return static
     */
    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false, 'closed_at' => '2026-03-31']);
    }
}
