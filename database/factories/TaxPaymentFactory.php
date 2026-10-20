<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use PryTax\Enums\TaxAllocation;
use PryTax\Enums\TaxPaymentStatus;
use PryTax\Enums\TaxType;
use PryTax\Models\Profile;
use PryTax\Models\TaxPayment;

/**
 * Class TaxPaymentFactory.
 *
 * Tax payments of a new profile: ЄП of III кв. 2026, paid on 15.10.2026, by default.
 *
 * @extends Factory<TaxPayment>
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Database\Factories
 */
class TaxPaymentFactory extends Factory
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
            'tax_type' => TaxType::SingleTax,
            'amount' => '2050.00',
            'paid_at' => '2026-10-15',
            'status' => TaxPaymentStatus::Paid,
            'allocation' => TaxAllocation::Quarter,
            'period_year' => 2026,
            'period_quarter' => 3,
        ];
    }

    /**
     * A payment, which was sent and hasn't reached the budget yet.
     *
     * @return static
     */
    public function sent(): static
    {
        return $this->state(fn () => ['status' => TaxPaymentStatus::Sent]);
    }
}
