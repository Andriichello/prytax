<?php

namespace Tests\Feature\Http\Middleware;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PryTax\Models\Payment;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;

/**
 * Class HandleIdempotencyKeyTest.
 *
 * POSTs with an Idempotency-Key (design.md §10): a double tap is one write, a key in use waits (409), a key too long
 * is refused (422), and a refused request isn't stored, so the corrected one goes through with the same key.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Http\Middleware
 */
class HandleIdempotencyKeyTest extends TestCase
{
    use ActsInProfiles;
    use RefreshDatabase;

    /**
     * Test that a request, whose key is in progress, is refused with 409, and one with a key too long with 422.
     *
     * @return void
     */
    public function testRefusesAKeyInProgressOrTooLong(): void
    {
        $profile = $this->createProfile();
        $user = $this->actAs($profile);
        $lock = Cache::lock("idempotency:{$user->id}:tap-1", 30);
        $lock->get();

        $this->withHeader('Idempotency-Key', 'tap-1')
            ->postJson($this->profilePath($profile, 'tax-payments'), [])
            ->assertConflict()
            ->assertJsonPath('message', 'A request with this Idempotency-Key is in progress.');
        $this->withHeader('Idempotency-Key', str_repeat('k', 65))
            ->postJson($this->profilePath($profile, 'tax-payments'), [])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Idempotency-Key must be at most 64 characters.');

        $lock->release();
    }

    /**
     * Test that a refused request isn't stored under its key, so the corrected one goes through with it, and its
     * replay answers the stored response.
     *
     * @return void
     */
    public function testStoresOnlySuccessfulResponses(): void
    {
        $profile = $this->createProfile();
        $this->actAs($profile);
        $payoneer = $this->createAccount($profile, ['name' => 'Payoneer USD']);
        $wise = $this->createAccount($profile);
        $payment = $this->inProfile($profile, fn () => Payment::factory()->create(['profile_id' => $profile->id, 'received_to_account_id' => $payoneer->id]));
        $transfer = [
            'from_account_id' => $payoneer->id, 'to_account_id' => $wise->id, 'amount_sent' => '1000.00', 'amount_received' => '985.00',
            'received_at' => '2026-10-10', 'allocations' => [['payment_id' => $payment->id, 'amount' => '1000.00']],
        ];

        $this->withHeader('Idempotency-Key', 'tap-2')
            ->postJson($this->profilePath($profile, 'transfers'), [...$transfer, 'amount_received' => '1100.00'])
            ->assertUnprocessable();
        $first = $this->withHeader('Idempotency-Key', 'tap-2')->postJson($this->profilePath($profile, 'transfers'), $transfer);
        $replay = $this->withHeader('Idempotency-Key', 'tap-2')->postJson($this->profilePath($profile, 'transfers'), $transfer);

        $first->assertCreated();
        $replay->assertCreated()->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('data.id', $first->json('data.id'));
        $this->assertDatabaseCount('account_transfers', 1);
    }
}
