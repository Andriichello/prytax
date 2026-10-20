<?php

namespace Tests\Feature\Services\Accounts;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PryTax\Models\Payment;
use PryTax\Services\Accounts\AccountStatement;
use PryTax\Services\Transfers\TransferRecorder;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;

/**
 * Class AccountStatementTest.
 *
 * The account page (design.md §16, Рахунок): money of receipts, which a foreign bank still holds past the
 * repatriation rule, is noted as late, as its notification is; and a transfer leaves on the day it was sent.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Services\Accounts
 */
class AccountStatementTest extends TestCase
{
    use ActsInProfiles;
    use RefreshDatabase;

    /**
     * Test that a receipt still held on a foreign bank after its quarter's end is late.
     *
     * @return void
     */
    public function testNotesMoneyLeftOnAForeignBankPastTheQuarterAsLate(): void
    {
        $this->travelTo('2026-10-20 10:00:00');
        $profile = $this->createProfile();
        $bank = $this->createAccount($profile, ['kind' => 'foreign_bank', 'name' => 'Revolut USD']);
        $this->inProfile($profile, fn () => Payment::factory()->create([
            'profile_id' => $profile->id, 'received_to_account_id' => $bank->id, 'received_at' => '2026-09-15',
        ]));

        $statement = $this->inProfile($profile, fn () => app(AccountStatement::class)->forYear($profile, $bank, 2026));

        $this->assertSame('2026-09-30', $statement['holdings'][0]['repatriation_deadline']);
        $this->assertTrue($statement['holdings'][0]['is_late']);
    }

    /**
     * Test that a transfer out of the account is dated on the day it was sent, and one into the account on the day
     * it arrived.
     *
     * @return void
     */
    public function testDatesATransferOutBySentDay(): void
    {
        $profile = $this->createProfile();
        $payoneer = $this->createAccount($profile, ['name' => 'Payoneer USD']);
        $wise = $this->createAccount($profile);
        $payment = $this->inProfile($profile, fn () => Payment::factory()->create(['profile_id' => $profile->id, 'received_to_account_id' => $payoneer->id]));
        $this->inProfile($profile, fn () => app(TransferRecorder::class)->record($profile, [
            'from_account_id' => $payoneer->id, 'to_account_id' => $wise->id, 'amount_sent' => '1000.00',
            'amount_received' => '985.00', 'sent_at' => '2026-10-08', 'received_at' => '2026-10-10',
            'allocations' => [['payment_id' => $payment->id, 'amount' => '1000.00']],
        ]));

        [$out, $in] = $this->inProfile($profile, fn () => [
            app(AccountStatement::class)->forYear($profile, $payoneer, 2026)['history'],
            app(AccountStatement::class)->forYear($profile, $wise, 2026)['history'],
        ]);

        $this->assertSame(['type' => 'transfer_out', 'date' => '2026-10-08'], [
            'type' => $out[0]['type'], 'date' => $out[0]['date'],
        ]);
        $this->assertSame('2026-10-10', $in[0]['date']);
    }
}
