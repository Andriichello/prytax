<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use PryTax\Enums\TaxType;
use PryTax\Models\Profile;
use PryTax\Models\TaxPaymentRequisite;

/**
 * Class TaxPaymentRequisiteFactory.
 *
 * Budget requisites of a new profile's taxes: ЄП in Kyiv from 2026 by default.
 *
 * @extends Factory<TaxPaymentRequisite>
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Database\Factories
 */
class TaxPaymentRequisiteFactory extends Factory
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
            'iban' => 'UA678999980000355679201022633',
            'recipient_name' => 'ГУК у м.Києві/Печерс.р-н/18050400',
            'recipient_code' => '37993783',
            'purpose_template' => '*;101;{tax_id};Єдиний податок за {period};{full_name}',
            'effective_from' => '2026-01-01',
        ];
    }
}
