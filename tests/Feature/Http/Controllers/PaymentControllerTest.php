<?php

namespace Tests\Feature\Http\Controllers;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PryTax\Enums\DeclarationStatus;
use PryTax\Models\Payment;
use PryTax\Services\Invoices\InvoiceWriter;
use PryTax\Services\Taxes\DeadlineScheduler;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;

/**
 * Class PaymentControllerTest.
 *
 * The receipts' endpoints (design.md §6.1, §8.3, §10): the snapshot of a receipt and how it covers its invoice, the
 * invoice and account it may land with, the NBU rate or a manual one, the lock of a declared quarter, idempotent
 * replays, and FX gains, which follow their transfers.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Http\Controllers
 */
class PaymentControllerTest extends TestCase
{
    use ActsInProfiles;
    use RefreshDatabase;

    /**
     * Test that a receipt is saved with its snapshot, and answers how it covers its invoice.
     *
     * @return void
     */
    public function testRecordsAReceiptWithItsSnapshotAndTheInvoicesCoverage(): void
    {
        Storage::fake('local');
        $profile = $this->createProfile();
        $this->actAs($profile, 'accountant');
        $wise = $this->createAccount($profile);
        $this->cacheRates(['USD' => ['2026-10-05' => '42.2297']]);
        $invoice = $this->createFrozenInvoice($profile, $wise);

        $response = $this->postJson($this->profilePath($profile, 'payments'), [
            'invoice_id' => $invoice->id,
            'amount' => '3 892,50',
            'received_to_account_id' => $wise->id,
            'received_amount' => '3877.50',
            'received_at' => '2026-10-05',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.nbu_rate', '42.2297')
            ->assertJsonPath('data.uah_amount', '164379.11')
            ->assertJsonPath('data.single_tax_uah', '8218.96')
            ->assertJsonPath('data.military_levy_uah', '1643.79')
            ->assertJsonPath('data.period_quarter', 4)
            ->assertJsonPath('meta.invoice.covers_total', true)
            ->assertJsonPath('meta.invoice.remaining', '0.00');
        $this->assertDatabaseHas('payments', [
            'invoice_id' => $invoice->id, 'amount' => '3892.50', 'received_amount' => '3877.50', 'received_currency' => 'USD',
        ]);
    }

    /**
     * Test that a draft invoice takes no receipts.
     *
     * @return void
     */
    public function testReturns422ForAReceiptOfADraft(): void
    {
        $profile = $this->createProfile();
        $this->actAs($profile);
        $wise = $this->createAccount($profile);
        [$client] = $this->createClientWithContract($profile);
        $draft = $this->inProfile($profile, fn () => app(InvoiceWriter::class)->create($profile, [
            'client_id' => $client->id, 'period_start' => '2026-10-01', 'period_end' => '2026-10-14',
            'items' => [['quantity' => '1', 'unit_price' => '100.00', 'description' => 'Work']],
        ]));

        $this->postJson($this->profilePath($profile, 'payments'), [
            'invoice_id' => $draft->id, 'amount' => '100.00', 'received_to_account_id' => $wise->id, 'received_at' => '2026-10-05',
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'invoice_id' => 'Receipts are recorded for frozen invoices only: ready, sent or paid.',
        ]);
        $this->assertDatabaseCount('payments', 0);
    }

    /**
     * Test that no receipt lands on an inactive account.
     *
     * @return void
     */
    public function testReturns422ForAReceiptOnAnInactiveAccount(): void
    {
        $profile = $this->createProfile();
        $this->actAs($profile);
        $closed = $this->createAccount($profile, ['is_active' => false]);
        $this->cacheRates(['USD' => ['2026-10-05' => '42.2297']]);

        $this->postJson($this->profilePath($profile, 'payments'), [
            ...$this->withoutInvoice($profile), 'amount' => '50.00', 'received_to_account_id' => $closed->id, 'received_at' => '2026-10-05',
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'received_to_account_id' => "The account is inactive, so receipts can't land on it.",
        ]);
    }

    /**
     * Test that the NBU rate of an uncached day is fetched for the receipt.
     *
     * @return void
     */
    public function testFetchesTheNbuRateOfAnUncachedDay(): void
    {
        Http::fake(['bank.gov.ua/NBUStatService/v1/statdirectory/exchange*' => Http::response('[{"rate":41.8710,"cc":"USD"}]')]);
        $profile = $this->createProfile();
        $this->actAs($profile);
        $wise = $this->createAccount($profile);

        $this->postJson($this->profilePath($profile, 'payments'), [
            ...$this->withoutInvoice($profile), 'amount' => '3892.50', 'received_to_account_id' => $wise->id, 'received_at' => '2026-07-07',
        ])->assertCreated()->assertJsonPath('data.uah_amount', '162982.87');

        $this->assertDatabaseHas('exchange_rates', ['currency' => 'USD', 'rate_date' => '2026-07-07', 'rate' => '41.8710']);
    }

    /**
     * Test that an unavailable NBU rate asks for a manual one, which is then taken.
     *
     * @return void
     */
    public function testAsksForAManualRateWhenTheNbuIsUnavailable(): void
    {
        Http::fake(['bank.gov.ua/*' => Http::response('Service Unavailable', 503)]);
        $profile = $this->createProfile();
        $this->actAs($profile);
        $wise = $this->createAccount($profile);
        $receipt = [...$this->withoutInvoice($profile), 'amount' => '100.00', 'received_to_account_id' => $wise->id, 'received_at' => '2026-07-07'];

        $this->postJson($this->profilePath($profile, 'payments'), $receipt)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['nbu_rate' => 'The NBU rate of USD on 07.07.2026 is unavailable. Enter the rate manually.']);

        $this->postJson($this->profilePath($profile, 'payments'), [...$receipt, 'nbu_rate' => '41,8710'])
            ->assertCreated()
            ->assertJsonPath('data.nbu_rate', '41.8710')
            ->assertJsonPath('data.rate_is_manual', true)
            ->assertJsonPath('data.uah_amount', '4187.10');
        $this->assertDatabaseCount('exchange_rates', 0);
    }

    /**
     * Test that a manual rate stays on its receipt: a receipt of another organization on the same day still asks
     * for a rate.
     *
     * @return void
     */
    public function testKeepsAManualRateOnItsOwnReceipt(): void
    {
        Http::fake(['bank.gov.ua/*' => Http::response('Service Unavailable', 503)]);
        $first = $this->createProfile();
        $second = $this->createProfile();
        $receipt = ['amount' => '100.00', 'received_at' => '2026-07-07'];
        $this->actAs($first);
        $this->postJson($this->profilePath($first, 'payments'), [
            ...$receipt, ...$this->withoutInvoice($first), 'received_to_account_id' => $this->createAccount($first)->id, 'nbu_rate' => '99.0000',
        ])->assertCreated();

        $this->actAs($second);
        $response = $this->postJson($this->profilePath($second, 'payments'), [
            ...$receipt, ...$this->withoutInvoice($second), 'received_to_account_id' => $this->createAccount($second)->id,
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['nbu_rate' => 'The NBU rate of USD on 07.07.2026 is unavailable. Enter the rate manually.']);
    }

    /**
     * Test that the NBU's rate is taken over a manual one, when it is available.
     *
     * @return void
     */
    public function testTakesTheNbuRateOverAManualOne(): void
    {
        $profile = $this->createProfile();
        $this->actAs($profile);
        $wise = $this->createAccount($profile);
        $this->cacheRates(['USD' => ['2026-10-05' => '42.2297']]);

        $response = $this->postJson($this->profilePath($profile, 'payments'), [
            ...$this->withoutInvoice($profile), 'amount' => '100.00', 'received_to_account_id' => $wise->id,
            'received_at' => '2026-10-05', 'nbu_rate' => '45.0000',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.nbu_rate', '42.2297')
            ->assertJsonPath('data.rate_is_manual', false)
            ->assertJsonPath('data.uah_amount', '4222.97');
    }

    /**
     * Test that a receipt saved at a manual rate takes the NBU's rate on its next update, once the NBU published it.
     *
     * @return void
     */
    public function testUpdatesAManualRateToTheNbusOncePublished(): void
    {
        Http::fake(['bank.gov.ua/*' => Http::response('Service Unavailable', 503)]);
        $profile = $this->createProfile();
        $this->actAs($profile);
        $wise = $this->createAccount($profile);
        $id = $this->postJson($this->profilePath($profile, 'payments'), [
            ...$this->withoutInvoice($profile), 'amount' => '100.00', 'received_to_account_id' => $wise->id,
            'received_at' => '2026-07-07', 'nbu_rate' => '40.0000',
        ])->json('data.id');
        $this->cacheRates(['USD' => ['2026-07-07' => '41.8710']]);

        $response = $this->patchJson($this->profilePath($profile, "payments/$id"), ['client_fee_amount' => '1.00']);

        $response->assertOk()
            ->assertJsonPath('data.nbu_rate', '41.8710')
            ->assertJsonPath('data.rate_is_manual', false)
            ->assertJsonPath('data.uah_amount', '4187.10')
            ->assertJsonPath('data.single_tax_uah', '209.36');
    }

    /**
     * Test that a receipt in a declared quarter needs a correction note (409), and with one marks the quarter and
     * the later declared quarters of the year as needing an уточнююча декларація.
     *
     * @return void
     */
    public function testRequiresACorrectionNoteInADeclaredQuarter(): void
    {
        $profile = $this->createProfile();
        $this->actAs($profile);
        $wise = $this->createAccount($profile);
        $this->cacheRates(['USD' => ['2026-05-06' => '41.3478']]);
        [$second, $third] = $this->inProfile($profile, fn () => [
            app(DeadlineScheduler::class)->ensure($profile, 2026, 2),
            app(DeadlineScheduler::class)->ensure($profile, 2026, 3),
        ]);
        foreach ([$second, $third] as $period) {
            $period->forceFill(['declaration_status' => DeclarationStatus::Accepted, 'locked_at' => now()])->save();
        }
        $receipt = [...$this->withoutInvoice($profile), 'amount' => '100.00', 'received_to_account_id' => $wise->id, 'received_at' => '2026-05-06'];

        $this->postJson($this->profilePath($profile, 'payments'), $receipt)
            ->assertConflict()
            ->assertJsonPath('message', 'Quarter 2 of 2026 is locked by its submitted declaration. Give a correction note to change it.');
        $this->assertDatabaseCount('payments', 0);

        $this->postJson($this->profilePath($profile, 'payments'), [...$receipt, 'correction_note' => 'Забутий бонус платформи'])
            ->assertCreated();
        $this->assertSame(DeclarationStatus::Corrected, $second->fresh()->declaration_status);
        $this->assertSame(DeclarationStatus::Corrected, $third->fresh()->declaration_status);
        $this->assertStringContainsString('Забутий бонус платформи', $second->fresh()->notes);
        $this->assertDatabaseHas('alerts', ['profile_id' => $profile->id, 'dedupe_key' => "correction:{$second->id}"]);
    }

    /**
     * Test that a replay of a receipt with its Idempotency-Key answers the first one without a second receipt, and
     * the key with another body is refused.
     *
     * @return void
     */
    public function testReplaysAReceiptWithTheSameIdempotencyKey(): void
    {
        $profile = $this->createProfile();
        $this->actAs($profile);
        $wise = $this->createAccount($profile);
        $this->cacheRates(['USD' => ['2026-10-05' => '42.2297']]);
        $receipt = [...$this->withoutInvoice($profile), 'amount' => '100.00', 'received_to_account_id' => $wise->id, 'received_at' => '2026-10-05'];

        $first = $this->withHeader('Idempotency-Key', 'tap-1')->postJson($this->profilePath($profile, 'payments'), $receipt);
        $replay = $this->withHeader('Idempotency-Key', 'tap-1')->postJson($this->profilePath($profile, 'payments'), $receipt);

        $first->assertCreated();
        $replay->assertCreated()->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('data.id', $first->json('data.id'));
        $this->assertDatabaseCount('payments', 1);

        $this->withHeader('Idempotency-Key', 'tap-1')
            ->postJson($this->profilePath($profile, 'payments'), [...$receipt, 'amount' => '200.00'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The Idempotency-Key was used for another request.');
    }

    /**
     * Test that a preview computes the receipt without saving it.
     *
     * @return void
     */
    public function testPreviewsAReceiptWithoutSavingIt(): void
    {
        Storage::fake('local');
        $profile = $this->createProfile();
        $this->actAs($profile);
        $wise = $this->createAccount($profile);
        $this->cacheRates(['USD' => ['2026-10-20' => '42.0870']]);
        $invoice = $this->createFrozenInvoice($profile, $wise);

        $this->postJson($this->profilePath($profile, 'payments/preview'), [
            'invoice_id' => $invoice->id, 'amount' => '792.50', 'received_to_account_id' => $wise->id,
            'received_amount' => '777.50', 'received_at' => '2026-10-20',
        ])->assertOk()
            ->assertJsonPath('data.uah_amount', '33353.95')
            ->assertJsonPath('data.single_tax_uah', '1667.70')
            ->assertJsonPath('data.military_levy_uah', '333.54')
            ->assertJsonPath('data.receipt_fee', '15.00')
            ->assertJsonPath('data.invoice.remaining', '3100.00');
        $this->assertDatabaseCount('payments', 0);
    }

    /**
     * Test that the preview of an edited receipt doesn't count the receipt twice in its invoice's coverage, and
     * allows the account it already landed on, although it is inactive since.
     *
     * @return void
     */
    public function testPreviewsAnEditOfAReceiptWithoutCountingItTwice(): void
    {
        Storage::fake('local');
        $profile = $this->createProfile();
        $this->actAs($profile);
        $wise = $this->createAccount($profile);
        $this->cacheRates(['USD' => ['2026-10-05' => '42.2297']]);
        $invoice = $this->createFrozenInvoice($profile, $wise);
        $payment = $this->recordReceipt($profile, $invoice, $wise);
        $this->inProfile($profile, fn () => $wise->forceFill(['is_active' => false])->save());

        $this->postJson($this->profilePath($profile, 'payments/preview'), [
            'payment_id' => $payment->id, 'invoice_id' => $invoice->id, 'amount' => '3892.50',
            'received_to_account_id' => $wise->id, 'received_at' => '2026-10-05', 'client_fee_amount' => '5.00',
        ])->assertOk()
            ->assertJsonPath('data.invoice.paid_amount', '3892.50')
            ->assertJsonPath('data.invoice.covers_total', true)
            ->assertJsonPath('data.invoice.overpaid', false)
            ->assertJsonPath('data.locked_quarter', null);
    }

    /**
     * Test that the preview of an edited receipt names a declared quarter only when the edit changes income, and
     * checks the quarter of the old date as well as of the new one.
     *
     * @return void
     */
    public function testPreviewsTheDeclaredQuarterOfAnEditOnlyWhenItChangesIncome(): void
    {
        Storage::fake('local');
        $profile = $this->createProfile();
        $this->actAs($profile);
        $wise = $this->createAccount($profile);
        $this->cacheRates(['USD' => ['2026-05-05' => '41.2000', '2026-10-05' => '42.2297']]);
        $invoice = $this->createFrozenInvoice($profile, $wise);
        $payment = $this->recordReceipt($profile, $invoice, $wise, ['received_at' => '2026-05-05']);
        $this->declareQuarter($profile, 2026, 2);
        $receipt = ['payment_id' => $payment->id, 'invoice_id' => $invoice->id, 'amount' => '3892.50', 'received_to_account_id' => $wise->id];

        $this->postJson($this->profilePath($profile, 'payments/preview'), [...$receipt, 'received_at' => '2026-05-05', 'client_fee_amount' => '5.00'])
            ->assertOk()
            ->assertJsonPath('data.locked_quarter', null);
        $this->postJson($this->profilePath($profile, 'payments/preview'), [...$receipt, 'received_at' => '2026-10-05'])
            ->assertOk()
            ->assertJsonPath('data.locked_quarter', ['year' => 2026, 'quarter' => 2]);
    }

    /**
     * Test that an FX gain can't be edited on its own (403): it follows its transfer.
     *
     * @return void
     */
    public function testReturns403WhenEditingAnFxGain(): void
    {
        $profile = $this->createProfile();
        $this->actAs($profile);
        $gain = $this->inProfile($profile, fn () => Payment::factory()->create(['profile_id' => $profile->id, 'kind' => 'fx_gain']));

        $this->patchJson($this->profilePath($profile, "payments/{$gain->id}"), ['description' => 'Mine'])->assertForbidden();
        $this->deleteJson($this->profilePath($profile, "payments/{$gain->id}"))->assertForbidden();
        $this->assertModelExists($gain);
    }

    /**
     * Test that one wire for two invoices is two receipts of one group, with what landed split by the invoiced
     * amounts, and the coverage of each invoice.
     *
     * @return void
     */
    public function testRecordsOneWireForSeveralInvoices(): void
    {
        Storage::fake('local');
        $profile = $this->createProfile();
        $this->actAs($profile, 'accountant');
        $wise = $this->createAccount($profile);
        $this->cacheRates(['USD' => ['2026-10-05' => '42.2297']]);
        $first = $this->createFrozenInvoice($profile, $wise);
        $second = $this->createFrozenInvoice($profile, $wise, ['items' => [['quantity' => '40.00']]]);

        $response = $this->postJson($this->profilePath($profile, 'payments/group'), [
            'receipts' => [
                ['invoice_id' => $first->id, 'amount' => '3892.50'],
                ['invoice_id' => $second->id, 'amount' => '1800.00'],
            ],
            'received_to_account_id' => $wise->id,
            'received_amount' => '5677.50',
            'received_at' => '2026-10-05',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.0.received_amount', '3882.24')
            ->assertJsonPath('data.1.received_amount', '1795.26')
            ->assertJsonPath('meta.invoices.0.covers_total', true)
            ->assertJsonPath('meta.invoices.1.covers_total', true);
        $this->assertSame($response->json('data.0.receipt_group_id'), $response->json('data.1.receipt_group_id'));
        $this->assertNotNull($response->json('data.0.receipt_group_id'));
        $this->assertDatabaseCount('payments', 2);
    }

    /**
     * Test that a payment without an invoice is recorded with its virtual invoice through the API.
     *
     * @return void
     */
    public function testRecordsAPaymentWithoutAnInvoice(): void
    {
        $profile = $this->createProfile();
        $this->actAs($profile, 'accountant');
        $wise = $this->createAccount($profile);
        $this->cacheRates(['USD' => ['2026-10-24' => '42.0510']]);

        $id = $this->postJson($this->profilePath($profile, 'payments'), [
            ...$this->withoutInvoice($profile), 'amount' => '500.00', 'received_to_account_id' => $wise->id, 'received_at' => '2026-10-24',
        ])->assertCreated()->assertJsonPath('data.uah_amount', '21025.50')->json('data.id');

        $this->getJson($this->profilePath($profile, "payments/$id?include=invoice"))
            ->assertOk()
            ->assertJsonPath('data.invoice.source', 'virtual')
            ->assertJsonPath('data.invoice.status', 'paid')
            ->assertJsonPath('data.invoice.number', null);
    }

    /**
     * Test that the preview of an edited payment without an invoice tells no coverage and no invoice to reopen: its
     * virtual invoice follows the receipt's amount, and is deleted, not reopened, when the receipt moves to an invoice.
     *
     * @return void
     */
    public function testPreviewsAnEditOfAPaymentWithoutAnInvoice(): void
    {
        Storage::fake('local');
        $profile = $this->createProfile();
        $this->actAs($profile);
        $wise = $this->createAccount($profile);
        $this->cacheRates(['USD' => ['2026-10-05' => '42.2297', '2026-10-24' => '42.0510']]);
        $receipt = ['received_to_account_id' => $wise->id, 'received_at' => '2026-10-24'];

        $payment = $this->postJson($this->profilePath($profile, 'payments'), [...$this->withoutInvoice($profile), ...$receipt, 'amount' => '500.00'])
            ->assertCreated()
            ->json('data');
        $id = $payment['id'];

        $this->postJson($this->profilePath($profile, 'payments/preview'), [
            ...$receipt, 'payment_id' => $id, 'invoice_id' => $payment['invoice_id'], 'amount' => '600.00',
        ])
            ->assertOk()
            ->assertJsonPath('data.uah_amount', '25230.60')
            ->assertJsonPath('data.invoice', null)
            ->assertJsonPath('data.reopens_invoice', null);

        $invoice = $this->createFrozenInvoice($profile, $wise);

        $this->postJson($this->profilePath($profile, 'payments/preview'), [...$receipt, 'payment_id' => $id, 'invoice_id' => $invoice->id, 'amount' => '500.00'])
            ->assertOk()
            ->assertJsonPath('data.invoice.paid_amount', '500.00')
            ->assertJsonPath('data.reopens_invoice', null);
    }

    /**
     * Test that a payment without an invoice needs a contract of its client and a period, and can't name an invoice
     * too (422).
     *
     * @return void
     */
    public function testReturns422ForAPaymentWithoutAnInvoiceWithoutItsContractOrPeriod(): void
    {
        $profile = $this->createProfile();
        $this->actAs($profile);
        $wise = $this->createAccount($profile);
        $receipt = ['amount' => '500.00', 'received_to_account_id' => $wise->id, 'received_at' => '2026-10-24'];
        $without = $this->withoutInvoice($profile)['virtual_invoice'];
        [, $othersContract] = $this->createClientWithContract($profile, ['abbreviation' => 'NW']);

        $this->postJson($this->profilePath($profile, 'payments'), [
            ...$receipt, 'virtual_invoice' => [...$without, 'contract_id' => $othersContract->id, 'period_end' => null],
        ])->assertUnprocessable()->assertJsonValidationErrors(['virtual_invoice.contract_id', 'virtual_invoice.period_end']);

        $this->postJson($this->profilePath($profile, 'payments'), [...$receipt, 'virtual_invoice' => $without, 'invoice_id' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('virtual_invoice');
        $this->assertDatabaseCount('invoices', 0);
    }
}
