<?php

namespace Tests\Feature\Http\Controllers;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PryTax\Models\TaxPaymentRequisite;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;

/**
 * Class TaxPaymentRequisiteControllerTest.
 *
 * Budget requisites of taxes: the ones in effect on a date, with the purpose of a payment filled in for the
 * copy-ready block of the tax payment's form.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Http\Controllers
 */
class TaxPaymentRequisiteControllerTest extends TestCase
{
    use ActsInProfiles;
    use RefreshDatabase;

    /**
     * Test that the requisites in effect are given with the purpose of a quarter's payment filled in.
     *
     * @return void
     */
    public function testFillsThePurposeOfTheRequisitesInEffect(): void
    {
        $profile = $this->createProfile(null, ['full_name' => 'Коваленко Олена Петрівна', 'tax_id' => '3214704821']);
        $this->actAs($profile, 'viewer');
        $requisite = ['tax_type' => 'single_tax', 'iban' => 'UA678999980000355679201022633', 'recipient_name' => 'ГУК у м.Києві/Печерс.р-н/18050400',
            'recipient_code' => '37993783', 'purpose_template' => '*;101;{tax_id};Єдиний податок за {period};{full_name}'];
        $this->inProfile($profile, function () use ($requisite) {
            TaxPaymentRequisite::query()->create([...$requisite, 'effective_from' => '2025-01-01', 'iban' => 'UA000000000000000000000000000']);
            TaxPaymentRequisite::query()->create([...$requisite, 'effective_from' => '2026-01-01']);
        });

        $response = $this->getJson($this->profilePath($profile, 'tax-payment-requisites/current?tax_type=single_tax&period_year=2026&period_quarter=3&date=2026-11-10'));

        $response->assertOk()
            ->assertJsonPath('data.iban', 'UA678999980000355679201022633')
            ->assertJsonPath('data.purpose', '*;101;3214704821;Єдиний податок за III квартал 2026 року;Коваленко Олена Петрівна');
    }
}
