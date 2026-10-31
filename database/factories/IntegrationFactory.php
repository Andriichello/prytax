<?php

namespace Database\Factories;

use Core\Models\Organization;
use Core\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use PryTax\Models\Integration;

/**
 * Class IntegrationFactory.
 *
 * An organization's Claude integration, off; `enabled()` turns it on by a user, with a key.
 *
 * @extends Factory<Integration>
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Database\Factories
 */
class IntegrationFactory extends Factory
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
            'kind' => Integration::CLAUDE,
        ];
    }

    /**
     * Turned on by the user, with the key.
     *
     * @param User $user
     * @param string $key
     *
     * @return static
     */
    public function enabled(User $user, string $key = 'prytax_test-key'): static
    {
        return $this->state(fn () => [
            'organization_id' => $user->organization_id,
            'enabled_at' => now(),
            'enabled_by_user_id' => $user->id,
            'key' => $key,
            'key_hash' => Integration::hashOf($key),
            'key_created_at' => now(),
        ]);
    }
}
