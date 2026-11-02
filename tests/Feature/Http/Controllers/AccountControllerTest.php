<?php

namespace Tests\Feature\Http\Controllers;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;

/**
 * Class AccountControllerTest.
 *
 * A new account with its first set of requisites (invoice.md §4): saved as its default, its codes normalized, or,
 * when the set is refused (422), no account at all.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Http\Controllers
 */
class AccountControllerTest extends TestCase
{
    use ActsInProfiles;
    use RefreshDatabase;

    /**
     * Test that a new account's requisites become its first set, the default.
     *
     * @return void
     */
    public function testCreatesTheAccountWithItsFirstSetAsTheDefault(): void
    {
        $profile = $this->createProfile();
        $this->actAs($profile);

        $response = $this->postJson($this->profilePath($profile, 'accounts'), [
            'name' => 'Monobank ФОП EUR', 'kind' => 'fop_bank', 'currency' => 'EUR',
            'requisites' => ['holder_name' => 'PE PRYKHODKO ANDRIY', 'iban' => 'ua21 3223 1300 0002 6007 2335 6600 1', 'swift' => 'unjsuaukxxx'],
        ]);

        $response->assertCreated();
        $this->getJson($this->profilePath($profile, "accounts/{$response->json('data.id')}?include=requisiteSets"))->assertOk()
            ->assertJsonCount(1, 'data.requisiteSets')
            ->assertJsonPath('data.requisiteSets.0.is_default', true)
            ->assertJsonPath('data.requisiteSets.0.iban', 'UA213223130000026007233566001')
            ->assertJsonPath('data.requisiteSets.0.swift', 'UNJSUAUKXXX');
    }

    /**
     * Test that a new account, whose first set is refused (422), isn't saved either.
     *
     * @return void
     */
    public function testReturns422ForAWrongFirstSetAndSavesNoAccount(): void
    {
        $profile = $this->createProfile();
        $this->actAs($profile);

        $response = $this->postJson($this->profilePath($profile, 'accounts'), [
            'name' => 'Monobank ФОП EUR', 'kind' => 'fop_bank', 'currency' => 'EUR', 'requisites' => ['bic' => 'CLJU'],
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors(['requisites.bic' => 'The requisites.bic field format is invalid.']);
        $this->assertDatabaseCount('accounts', 0);
    }
}
