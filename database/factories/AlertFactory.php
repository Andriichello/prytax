<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use PryTax\Enums\AlertCategory;
use PryTax\Enums\AlertSeverity;
use PryTax\Models\Alert;
use PryTax\Models\Profile;

/**
 * Class AlertFactory.
 *
 * Notifications of a new profile: an open, informational one about an overdue invoice by default.
 *
 * @extends Factory<Alert>
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Database\Factories
 */
class AlertFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'profile_id' => Profile::factory(),
            'type' => 'overdue',
            'category' => AlertCategory::Invoices,
            'severity' => AlertSeverity::Info,
            'dedupe_key' => fn () => 'test:' . Str::random(12),
            'title' => '2026-10-14-13 · Brightline Labs LLC',
            'body' => 'Термін оплати минув 28.10.2026.',
            'payload' => [],
        ];
    }

    /**
     * A notification, whose condition cleared.
     *
     * @return static
     */
    public function resolved(): static
    {
        return $this->state(fn () => ['resolved_at' => now()]);
    }
}
