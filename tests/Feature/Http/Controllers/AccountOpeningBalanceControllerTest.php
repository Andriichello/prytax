<?php

namespace Tests\Feature\Http\Controllers;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;

/**
 * Class AccountOpeningBalanceControllerTest.
 *
 * Opening balances of an account: one per date, refused with 422 rather than failing the database.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Http\Controllers
 */
class AccountOpeningBalanceControllerTest extends TestCase
{
    use ActsInProfiles;
    use RefreshDatabase;

    /**
     * Test that a second opening balance of an account on a date is refused (422), while another account may have one.
     *
     * @return void
     */
    public function testReturns422ForASecondOpeningBalanceOfADate(): void
    {
        $profile = $this->createProfile();
        $this->actAs($profile);
        $wise = $this->createAccount($profile);
        $payoneer = $this->createAccount($profile, ['name' => 'Payoneer USD']);
        $balance = ['as_of' => '2026-01-01', 'amount' => '1200.00'];

        $this->postJson($this->profilePath($profile, "accounts/{$wise->id}/opening-balances"), $balance)->assertCreated();
        $this->postJson($this->profilePath($profile, "accounts/{$wise->id}/opening-balances"), $balance)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['as_of' => 'The as of has already been taken.']);
        $this->postJson($this->profilePath($profile, "accounts/{$payoneer->id}/opening-balances"), $balance)->assertCreated();
    }
}
