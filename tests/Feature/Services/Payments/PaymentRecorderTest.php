<?php

namespace Tests\Feature\Services\Payments;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PryTax\Enums\DeclarationStatus;
use PryTax\Models\Account;
use PryTax\Models\Invoice;
use PryTax\Models\Payment;
use PryTax\Models\Profile;
use PryTax\Services\Invoices\InvoiceLifecycle;
use PryTax\Services\Payments\PaymentRecorder;
use PryTax\Services\Payments\RefundRecorder;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;

/**
 * Class PaymentRecorderTest.
 *
 * Receipts (design.md §6.1): an advance links to an invoice of its own currency only, receipts land on business
 * accounts, an amount never drops below what was refunded, only a change of income recomputes the snapshot, so
 * correcting the landed amount needs no correction of a declared quarter, and a paid invoice, which a deleted,
 * reduced or moved receipt leaves uncovered, returns to unpaid.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Services\Payments
 */
class PaymentRecorderTest extends TestCase
{
    use ActsInProfiles;
    use RefreshDatabase;

    /**
     * Create an invoice of 3 892.50 USD, paid by one receipt of 05.10.2026, and sent before that, unless told not to.
     *
     * @param Profile $profile
     * @param Account $account
     * @param bool $sent
     *
     * @return array{0: Invoice, 1: Payment}
     */
    private function paidInvoice(Profile $profile, Account $account, bool $sent = true): array
    {
        $invoice = $this->createFrozenInvoice($profile, $account);
        $payment = $this->recordReceipt($profile, $invoice, $account);

        $this->inProfile($profile, function () use ($profile, $invoice, $sent) {
            if ($sent) {
                app(InvoiceLifecycle::class)->send($profile, $invoice, '2026-10-01');
            }

            app(InvoiceLifecycle::class)->pay($profile, $invoice);
        });

        return [$invoice->fresh(), $payment];
    }

    /**
     * Test that an advance becomes the receipt of an invoice in its currency, with its snapshot kept.
     *
     * @return void
     */
    public function testLinksAnAdvanceToAnInvoiceOfItsCurrency(): void
    {
        Storage::fake('local');
        $profile = $this->createProfile();
        $wise = $this->createAccount($profile);
        $this->cacheRates(['USD' => ['2026-10-05' => '42.2297']]);
        $invoice = $this->createFrozenInvoice($profile, $wise);
        $advance = $this->inProfile($profile, fn () => app(PaymentRecorder::class)->record($profile, [
            'kind' => 'advance', 'currency' => 'USD', 'amount' => '3892.50', 'received_to_account_id' => $wise->id,
            'received_at' => '2026-10-05',
        ]));

        $linked = $this->inProfile($profile, fn () => app(PaymentRecorder::class)->update($profile, $advance, ['invoice_id' => $invoice->id]));

        $this->assertSame('invoice', $linked->kind->value);
        $this->assertSame($invoice->id, $linked->invoice_id);
        $this->assertSame('164379.11', $linked->uah_amount);
    }

    /**
     * Test that an advance in one currency can't become the receipt of an invoice in another.
     *
     * @return void
     */
    public function testReturns422WhenAnAdvanceLinksToAnInvoiceInAnotherCurrency(): void
    {
        Storage::fake('local');
        $profile = $this->createProfile();
        $wise = $this->createAccount($profile);
        $euros = $this->createAccount($profile, ['name' => 'Wise EUR', 'currency' => 'EUR']);
        $this->cacheRates(['EUR' => ['2026-10-05' => '48.0000']]);
        $invoice = $this->createFrozenInvoice($profile, $wise);
        $advance = $this->inProfile($profile, fn () => app(PaymentRecorder::class)->record($profile, [
            'kind' => 'advance', 'currency' => 'EUR', 'amount' => '1000.00', 'received_to_account_id' => $euros->id,
            'received_at' => '2026-10-05',
        ]));

        try {
            $this->inProfile($profile, fn () => app(PaymentRecorder::class)->update($profile, $advance, ['invoice_id' => $invoice->id]));
            $this->fail('No exception was thrown.');
        } catch (ValidationException $exception) {
            $this->assertSame(['invoice_id' => ['The receipt is in EUR, so it belongs to an invoice in EUR only.']], $exception->errors());
        }

        $this->assertSame('advance', $advance->fresh()->kind->value);
        $this->assertSame('48000.00', $advance->fresh()->uah_amount);
    }

    /**
     * Test that no receipt lands on a personal account, which isn't a business one.
     *
     * @return void
     */
    public function testReturns422ForAReceiptOnAPersonalAccount(): void
    {
        $profile = $this->createProfile();
        $card = $this->createAccount($profile, ['kind' => 'personal', 'name' => 'Картка']);
        $this->cacheRates(['USD' => ['2026-10-05' => '42.2297']]);

        try {
            $this->inProfile($profile, fn () => app(PaymentRecorder::class)->record($profile, [
                'kind' => 'other', 'currency' => 'USD', 'amount' => '100.00', 'received_to_account_id' => $card->id,
                'received_at' => '2026-10-05',
            ]));
            $this->fail('No exception was thrown.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['received_to_account_id' => ["A personal account isn't a business one, so receipts can't land on it."]],
                $exception->errors()
            );
        }

        $this->assertDatabaseCount('payments', 0);
    }

    /**
     * Test that a receipt's amount can't drop below what was refunded of it.
     *
     * @return void
     */
    public function testReturns422WhenTheAmountDropsBelowItsRefunds(): void
    {
        $profile = $this->createProfile();
        $wise = $this->createAccount($profile);
        $this->cacheRates(['USD' => ['2026-10-05' => '42.2297', '2026-10-10' => '42.0000']]);
        $payment = $this->inProfile($profile, fn () => app(PaymentRecorder::class)->record($profile, [
            'kind' => 'other', 'currency' => 'USD', 'amount' => '1000.00', 'received_to_account_id' => $wise->id,
            'received_at' => '2026-10-05',
        ]));
        $this->inProfile($profile, fn () => app(RefundRecorder::class)->record($profile, $payment, [
            'amount' => '600.00', 'from_account_id' => $wise->id, 'refunded_at' => '2026-10-10',
        ]));

        try {
            $this->inProfile($profile, fn () => app(PaymentRecorder::class)->update($profile, $payment, ['amount' => '500.00']));
            $this->fail('No exception was thrown.');
        } catch (ValidationException $exception) {
            $this->assertSame(['amount' => ["The receipt was refunded 600.00 USD, so its amount can't be less."]], $exception->errors());
        }

        $this->assertSame('1000.00', $payment->fresh()->amount);
    }

    /**
     * Test that correcting the landed amount of a receipt in a declared quarter keeps its snapshot, so it needs no
     * correction note, while the taxable base is the invoiced amount.
     *
     * @return void
     */
    public function testCorrectsTheLandedAmountWithoutChangingTheIncome(): void
    {
        $profile = $this->createProfile();
        $wise = $this->createAccount($profile);
        $this->cacheRates(['USD' => ['2026-10-05' => '42.2297']]);
        $payment = $this->inProfile($profile, fn () => app(PaymentRecorder::class)->record($profile, [
            'kind' => 'other', 'currency' => 'USD', 'amount' => '3892.50', 'received_to_account_id' => $wise->id,
            'received_amount' => '3877.50', 'received_at' => '2026-10-05',
        ]));
        $period = $this->declareQuarter($profile, 2026, 4);

        $updated = $this->inProfile($profile, fn () => app(PaymentRecorder::class)->update($profile, $payment, ['received_amount' => '3870.00']));

        $this->assertSame('3870.00', $updated->received_amount);
        $this->assertSame('164379.11', $updated->uah_amount);
        $this->assertSame(DeclarationStatus::Accepted, $period->fresh()->declaration_status);
    }

    /**
     * Test that deleting the receipt of a paid invoice returns the invoice to sent.
     *
     * @return void
     */
    public function testReturnsAPaidInvoiceToSentWhenItsReceiptIsDeleted(): void
    {
        Storage::fake('local');
        $profile = $this->createProfile();
        $wise = $this->createAccount($profile);
        $this->cacheRates(['USD' => ['2026-10-05' => '42.2297']]);
        [$invoice, $payment] = $this->paidInvoice($profile, $wise);

        $this->inProfile($profile, fn () => app(PaymentRecorder::class)->delete($profile, $payment));

        $this->assertSame('sent', $invoice->fresh()->status->value);
        $this->assertNull($invoice->fresh()->paid_at);
    }

    /**
     * Test that reducing the receipt of an invoice paid without being sent returns the invoice to ready, while a
     * reduction, which still covers the total, keeps it paid.
     *
     * @return void
     */
    public function testReturnsAnInvoicePaidWithoutBeingSentToReadyWhenItsReceiptIsReduced(): void
    {
        Storage::fake('local');
        $profile = $this->createProfile();
        $wise = $this->createAccount($profile);
        $this->cacheRates(['USD' => ['2026-10-05' => '42.2297']]);
        [$invoice, $payment] = $this->paidInvoice($profile, $wise, sent: false);
        $this->inProfile($profile, fn () => app(PaymentRecorder::class)->update($profile, $payment, ['amount' => '3900.00']));
        $this->assertSame('paid', $invoice->fresh()->status->value);

        $this->inProfile($profile, fn () => app(PaymentRecorder::class)->update($profile, $payment, ['amount' => '3800.00']));

        $this->assertSame('ready', $invoice->fresh()->status->value);
        $this->assertNull($invoice->fresh()->paid_at);
    }

    /**
     * Test that the preview names the paid invoice, which moving its receipt to another invoice would leave
     * uncovered, and that saving the move returns that invoice to sent.
     *
     * @return void
     */
    public function testWarnsOfAndReopensThePaidInvoiceAReceiptMovesFrom(): void
    {
        Storage::fake('local');
        $profile = $this->createProfile();
        $wise = $this->createAccount($profile);
        $this->cacheRates(['USD' => ['2026-10-05' => '42.2297']]);
        [$invoice, $payment] = $this->paidInvoice($profile, $wise);
        $other = $this->createFrozenInvoice($profile, $wise);

        $preview = $this->inProfile($profile, fn () => app(PaymentRecorder::class)->preview($profile, [
            'payment_id' => $payment->id,
            'invoice_id' => $other->id,
        ]));
        $this->inProfile($profile, fn () => app(PaymentRecorder::class)->update($profile, $payment, ['invoice_id' => $other->id]));

        $this->assertSame(['id' => $invoice->id, 'number' => $invoice->number], $preview['reopens_invoice']);
        $this->assertSame('sent', $invoice->fresh()->status->value);
        $this->assertSame('ready', $other->fresh()->status->value);
    }
}
