<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use PryTax\Models\Holiday;

/**
 * Class HolidayFactory.
 *
 * Public holidays, which deadlines roll over (none while martial law lasts).
 *
 * @extends Factory<Holiday>
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Database\Factories
 */
class HolidayFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'date' => '2027-01-01',
            'name' => 'Новий рік',
        ];
    }
}
