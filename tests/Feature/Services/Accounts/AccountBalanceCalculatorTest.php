<?php

namespace Tests\Feature\Services\Accounts;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PryTax\Models\AccountMovement;
use PryTax\Models\AccountOpeningBalance;
use PryTax\Models\Payment;
use PryTax\Models\TaxPayment;
use PryTax\Services\Accounts\AccountBalanceCalculator;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;

/**
 * Class AccountBalanceCalculatorTest.
 *
 * An account's tracked balance (design.md §3): its latest opening balance, plus what came in since (receipts,
 * transfers, movements), less what went out (transfers, tax payments, refunds, movements); flows before the opening
 * balance are in it already.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Services\Accounts
 */
class AccountBalanceCalculatorTest extends TestCase
{
    use ActsInProfiles;
    use RefreshDatabase;

    /**
     * Test that the balance is the opening one plus the flows since it.
     *
     * @return void
     */
    public function testAddsTheFlowsSinceTheOpeningBalance(): void
    {
        $profile = $this->createProfile();
        $bank = $this->createAccount($profile, ['kind' => 'fop_bank', 'currency' => 'UAH', 'name' => 'ПриватБанк ФОП']);
        $this->inProfile($profile, function () use ($profile, $bank) {
            $of = ['profile_id' => $profile->id];
            AccountOpeningBalance::factory()->create([...$of, 'account_id' => $bank->id, 'as_of' => '2026-07-01', 'amount' => '10000.00']);
            Payment::factory()->create([...$of, 'currency' => 'UAH', 'amount' => '5000.00', 'received_to_account_id' => $bank->id, 'received_at' => '2026-06-30']);
            Payment::factory()->create([...$of, 'currency' => 'UAH', 'amount' => '8000.00', 'received_to_account_id' => $bank->id, 'received_at' => '2026-07-15']);
            TaxPayment::factory()->create([...$of, 'amount' => '400.00', 'paid_from_account_id' => $bank->id, 'paid_at' => '2026-07-20']);
            TaxPayment::factory()->sent()->create([...$of, 'amount' => '100.00', 'paid_from_account_id' => $bank->id, 'paid_at' => '2026-07-21']);
            AccountMovement::factory()->create([...$of, 'account_id' => $bank->id, 'amount' => '25.00', 'booked_at' => '2026-07-31']);
        });

        $balance = $this->inProfile($profile, fn () => app(AccountBalanceCalculator::class)->balance($bank));

        $this->assertSame('17475.00', $balance);
    }
}
