<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use PryTax\Enums\DeclarationStatus;
use PryTax\Models\Profile;
use PryTax\Models\TaxPeriod;
use PryTax\Services\Taxes\DeadlineScheduler;

/**
 * Class TaxPeriodFactory.
 *
 * Tax periods of a new profile: III кв. 2026, not declared, with its deadlines, by default.
 *
 * @extends Factory<TaxPeriod>
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Database\Factories
 */
class TaxPeriodFactory extends Factory
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
            'year' => 2026,
            'quarter' => 3,
            'is_annual' => fn (array $attributes) => $attributes['quarter'] === 4,
            'declaration_status' => DeclarationStatus::None,
            'declaration_due_at' => fn (array $attributes) => $this->deadline($attributes, 'declaration_due_at'),
            'tax_due_at' => fn (array $attributes) => $this->deadline($attributes, 'tax_due_at'),
            'esv_due_at' => fn (array $attributes) => $this->deadline($attributes, 'esv_due_at'),
        ];
    }

    /**
     * A quarter, whose declaration was accepted, which locks it.
     *
     * @return static
     */
    public function accepted(): static
    {
        return $this->state(fn () => ['declaration_status' => DeclarationStatus::Accepted, 'locked_at' => now()]);
    }

    /**
     * Get a deadline of the quarter of the attributes.
     *
     * @param array<string, mixed> $attributes
     * @param string $deadline
     *
     * @return string
     */
    protected function deadline(array $attributes, string $deadline): string
    {
        return app(DeadlineScheduler::class)->deadlines($attributes['year'], $attributes['quarter'])[$deadline]->toDateString();
    }
}
