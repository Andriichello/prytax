<?php

namespace Tests\Feature\Services\Accounts;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PryTax\Models\Payment;
use PryTax\Services\Accounts\AccountLimitCalculator;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;

/**
 * Class AccountLimitCalculatorTest.
 *
 * Progress towards an account's limit (design.md §3, accounts): the client receipts within the current calendar
 * window, and none of a personal account, which isn't a business one.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Services\Accounts
 */
class AccountLimitCalculatorTest extends TestCase
{
    use ActsInProfiles;
    use RefreshDatabase;

    /**
     * Test that a yearly limit counts the receipts of the calendar year only.
     *
     * @return void
     */
    public function testCountsTheReceiptsOfTheCalendarYear(): void
    {
        $profile = $this->createProfile();
        $payoneer = $this->createAccount($profile, ['name' => 'Payoneer USD', 'limit_amount' => '10000.00', 'limit_period' => 'year']);
        $this->inProfile($profile, function () use ($profile, $payoneer) {
            Payment::factory()->create(['profile_id' => $profile->id, 'received_to_account_id' => $payoneer->id, 'received_at' => '2026-01-01']);
            Payment::factory()->create(['profile_id' => $profile->id, 'received_to_account_id' => $payoneer->id, 'received_at' => '2025-12-31']);
        });

        $progress = $this->inProfile($profile, fn () => app(AccountLimitCalculator::class)->progress($payoneer, now()->setDate(2026, 10, 20)));

        $this->assertSame('2026-01-01', $progress['from']);
        $this->assertSame('2026-12-31', $progress['to']);
        $this->assertSame('1000.00', $progress['used']);
        $this->assertSame('10', $progress['percent']);
    }

    /**
     * Test that a personal account's limit isn't tracked.
     *
     * @return void
     */
    public function testTracksNoLimitOfAPersonalAccount(): void
    {
        $profile = $this->createProfile();
        $card = $this->createAccount($profile, [
            'kind' => 'personal', 'name' => 'Картка', 'limit_amount' => '1000.00', 'limit_period' => 'month',
        ]);

        $this->assertNull($this->inProfile($profile, fn () => app(AccountLimitCalculator::class)->progress($card)));
    }
}
