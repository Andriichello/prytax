<?php

namespace Database\Factories;

use Core\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use PryTax\Models\IdempotencyKey;

/**
 * Class IdempotencyKeyFactory.
 *
 * Stored responses of POSTs by their Idempotency-Key: a created receipt of a new user by default.
 *
 * @extends Factory<IdempotencyKey>
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Database\Factories
 */
class IdempotencyKeyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'key' => fn () => (string) Str::uuid(),
            'request_hash' => fn () => hash('sha256', Str::random(32)),
            'response_status' => 201,
            'response_body' => '{"data":[],"message":"Created"}',
            'created_at' => now(),
        ];
    }
}
