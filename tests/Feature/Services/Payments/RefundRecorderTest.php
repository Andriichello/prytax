<?php

namespace Tests\Feature\Services\Payments;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PryTax\Models\Account;
use PryTax\Models\Payment;
use PryTax\Models\Profile;
use PryTax\Services\Invoices\InvoiceLifecycle;
use PryTax\Services\Payments\RefundRecorder;
use PryTax\Services\Transfers\HoldingCalculator;
use PryTax\Services\Transfers\TransferRecorder;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;

/**
 * Class RefundRecorderTest.
 *
 * Money returned to a client (design.md §6.6): at the NBU rate of its own day, never more than the receipt, never
 * before it, taken from what the receipt holds on the account it leaves from, and never of an FX gain. A refund,
 * which leaves a paid invoice uncovered, returns it to unpaid.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Services\Payments
 */
class RefundRecorderTest extends TestCase
{
    use ActsInProfiles;
    use RefreshDatabase;

    /**
     * Create a profile with a $1 000 receipt of 05.10.2026 on Payoneer.
     *
     * @return array{0: Profile, 1: Payment, 2: Account}
     */
    private function receiptOnPayoneer(): array
    {
        $profile = $this->createProfile();
        $payoneer = $this->createAccount($profile, ['name' => 'Payoneer USD']);
        $payment = $this->inProfile($profile, fn () => Payment::factory()->create([
            'profile_id' => $profile->id,
            'received_to_account_id' => $payoneer->id,
        ]));

        return [$profile, $payment, $payoneer];
    }

    /**
     * Record a refund of the receipt in the profile.
     *
     * @param Profile $profile
     * @param Payment $payment
     * @param Account $from
     * @param string $amount
     * @param string $date
     *
     * @return mixed
     */
    private function refund(Profile $profile, Payment $payment, Account $from, string $amount, string $date = '2026-11-10'): mixed
    {
        return $this->inProfile($profile, fn () => app(RefundRecorder::class)->record($profile, $payment, [
            'amount' => $amount,
            'from_account_id' => $from->id,
            'refunded_at' => $date,
        ]));
    }

    /**
     * Test that a refund is saved at the NBU rate of its day, in its own quarter, and takes the money it returns
     * from what the receipt holds on the account.
     *
     * @return void
     */
    public function testRecordsARefundAtTheRateOfItsDayFromTheReceiptsHolding(): void
    {
        [$profile, $payment, $payoneer] = $this->receiptOnPayoneer();
        $this->cacheRates(['USD' => ['2026-11-10' => '41.5000']]);

        $refund = $this->refund($profile, $payment, $payoneer, '200.00');
        $holding = $this->inProfile($profile, fn () => app(HoldingCalculator::class)->holding($payment->fresh(), $payoneer));

        $this->assertSame('41.5000', $refund->nbu_rate);
        $this->assertSame('8300.00', $refund->uah_amount);
        $this->assertSame(4, $refund->period_quarter);
        $this->assertSame('800.00', (string) $holding);
    }

    /**
     * Test that the refunds of a receipt can't exceed its amount.
     *
     * @return void
     */
    public function testReturns422ForRefundsBeyondTheReceipt(): void
    {
        [$profile, $payment, $payoneer] = $this->receiptOnPayoneer();
        $this->cacheRates(['USD' => ['2026-11-10' => '41.5000']]);
        $this->refund($profile, $payment, $payoneer, '600.00');

        try {
            $this->refund($profile, $payment, $payoneer, '500.00');
            $this->fail('No exception was thrown.');
        } catch (ValidationException $exception) {
            $this->assertSame(['amount' => ["Refunds can't exceed the receipt: 400.00 USD is left to refund."]], $exception->errors());
        }

        $this->assertDatabaseCount('refunds', 1);
    }

    /**
     * Test that a refund can't precede its receipt.
     *
     * @return void
     */
    public function testReturns422ForARefundBeforeItsReceipt(): void
    {
        [$profile, $payment, $payoneer] = $this->receiptOnPayoneer();

        try {
            $this->refund($profile, $payment, $payoneer, '100.00', '2026-10-01');
            $this->fail('No exception was thrown.');
        } catch (ValidationException $exception) {
            $this->assertSame(['refunded_at' => ["A refund can't precede its receipt of 05.10.2026."]], $exception->errors());
        }
    }

    /**
     * Test that a refund leaves from where the receipt's money is: not from an account it moved away from, while
     * it may from the account it moved to.
     *
     * @return void
     */
    public function testRefundsNoMoreThanTheAccountHoldsOfTheReceipt(): void
    {
        [$profile, $payment, $payoneer] = $this->receiptOnPayoneer();
        $wise = $this->createAccount($profile);
        $this->cacheRates(['USD' => ['2026-11-10' => '41.5000']]);
        $this->inProfile($profile, fn () => app(TransferRecorder::class)->record($profile, [
            'from_account_id' => $payoneer->id, 'to_account_id' => $wise->id, 'amount_sent' => '1000.00',
            'amount_received' => '985.00', 'received_at' => '2026-10-10',
            'allocations' => [['payment_id' => $payment->id, 'amount' => '1000.00']],
        ]));

        try {
            $this->refund($profile, $payment, $payoneer, '100.00');
            $this->fail('No exception was thrown.');
        } catch (ValidationException $exception) {
            $this->assertSame(['from_account_id' => ['The receipt holds only 0.00 USD on Payoneer USD.']], $exception->errors());
        }
        $this->refund($profile, $payment, $wise, '100.00');

        $holding = $this->inProfile($profile, fn () => app(HoldingCalculator::class)->holding($payment->fresh(), $wise));
        $this->assertSame('885.00', (string) $holding);
    }

    /**
     * Test that an FX gain, which isn't client money, isn't refunded.
     *
     * @return void
     */
    public function testReturns422ForARefundOfAnFxGain(): void
    {
        $profile = $this->createProfile();
        $bank = $this->createAccount($profile, ['kind' => 'fop_bank', 'currency' => 'UAH', 'name' => 'ПриватБанк ФОП']);
        $gain = $this->inProfile($profile, fn () => Payment::factory()->create([
            'profile_id' => $profile->id, 'kind' => 'fx_gain', 'currency' => 'UAH', 'amount' => '500.00',
            'received_to_account_id' => $bank->id, 'received_amount' => '0.00',
        ]));

        try {
            $this->refund($profile, $gain, $bank, '100.00');
            $this->fail('No exception was thrown.');
        } catch (ValidationException $exception) {
            $this->assertSame(['payment_id' => ["An FX gain isn't client money, so it isn't refunded."]], $exception->errors());
        }
    }

    /**
     * Test that refunding what a client overpaid keeps the invoice paid, while a refund, which leaves the invoice
     * uncovered, returns it to sent.
     *
     * @return void
     */
    public function testReturnsAPaidInvoiceToSentOnceARefundLeavesItUncovered(): void
    {
        Storage::fake('local');
        $profile = $this->createProfile();
        $wise = $this->createAccount($profile);
        $this->cacheRates(['USD' => ['2026-10-05' => '42.2297', '2026-10-10' => '42.0000']]);
        $invoice = $this->createFrozenInvoice($profile, $wise);
        $payment = $this->recordReceipt($profile, $invoice, $wise, ['amount' => '3992.50']);
        $this->inProfile($profile, function () use ($profile, $invoice) {
            app(InvoiceLifecycle::class)->send($profile, $invoice, '2026-10-01');
            app(InvoiceLifecycle::class)->pay($profile, $invoice);
        });

        $this->refund($profile, $payment, $wise, '100.00', '2026-10-10');
        $this->assertSame('paid', $invoice->fresh()->status->value);

        $this->refund($profile, $payment, $wise, '0.01', '2026-10-10');

        $this->assertSame('sent', $invoice->fresh()->status->value);
        $this->assertNull($invoice->fresh()->paid_at);
    }
}
