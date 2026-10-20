<?php

namespace Tests\Feature\Http\Controllers;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PryTax\Models\ChargeType;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;

/**
 * Class ContractControllerTest.
 *
 * Contracts of a client with their hourly rates: two rates of one charge type starting on one date are refused with
 * 422, rather than failing the database's unique index.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Http\Controllers
 */
class ContractControllerTest extends TestCase
{
    use ActsInProfiles;
    use RefreshDatabase;

    /**
     * Test that two default rates starting on one date are refused, while a charge type's rate may start then too.
     *
     * @return void
     */
    public function testReturns422ForTwoRatesOfAChargeTypeStartingOnOneDate(): void
    {
        $profile = $this->createProfile();
        $this->actAs($profile);
        [$client] = $this->createClientWithContract($profile);
        $overhead = $this->inProfile($profile, fn () => ChargeType::factory()->create([
            'profile_id' => $profile->id, 'client_id' => $client->id, 'name' => 'Overhead',
        ]));
        $contract = ['name' => 'Service Agreement', 'started_at' => '2026-01-01', 'currency' => 'USD'];
        $path = $this->profilePath($profile, "clients/{$client->id}/contracts");

        $this->postJson($path, [...$contract, 'rates' => [
            ['charge_type_id' => null, 'effective_from' => '2026-01-01', 'hourly_rate' => '45.00'],
            ['charge_type_id' => null, 'effective_from' => '2026-01-01', 'hourly_rate' => '50.00'],
        ]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['rates' => 'Two rates have the same work type and start date.']);

        $this->postJson($path, [...$contract, 'rates' => [
            ['charge_type_id' => null, 'effective_from' => '2026-01-01', 'hourly_rate' => '45.00'],
            ['charge_type_id' => $overhead->id, 'effective_from' => '2026-01-01', 'hourly_rate' => '30.00'],
        ]])->assertCreated();
    }
}
