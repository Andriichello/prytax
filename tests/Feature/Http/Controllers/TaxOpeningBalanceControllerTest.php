<?php

namespace Tests\Feature\Http\Controllers;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;

/**
 * Class TaxOpeningBalanceControllerTest.
 *
 * Opening balances of taxes, from which the running balances start: each is the balance before a quarter, one per
 * tax and date.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Http\Controllers
 */
class TaxOpeningBalanceControllerTest extends TestCase
{
    use ActsInProfiles;
    use RefreshDatabase;

    /**
     * Test that an opening balance is dated on the first day of a quarter, once per tax (422 otherwise).
     *
     * @return void
     */
    public function testReturns422ForAnOpeningBalanceOffAQuartersStartOrTwice(): void
    {
        $profile = $this->createProfile();
        $this->actAs($profile);
        $balance = ['tax_type' => 'single_tax', 'as_of' => '2026-01-01', 'amount' => '51.00'];

        $this->postJson($this->profilePath($profile, 'tax-opening-balances'), [...$balance, 'as_of' => '2026-02-15'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['as_of']);
        $this->postJson($this->profilePath($profile, 'tax-opening-balances'), $balance)->assertCreated();
        $this->postJson($this->profilePath($profile, 'tax-opening-balances'), $balance)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['as_of' => 'The as of has already been taken.']);
        $this->postJson($this->profilePath($profile, 'tax-opening-balances'), [...$balance, 'tax_type' => 'military_levy'])->assertCreated();
    }
}
