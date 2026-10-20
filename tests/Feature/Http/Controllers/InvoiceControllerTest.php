<?php

namespace Tests\Feature\Http\Controllers;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PryTax\Models\Invoice;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;

/**
 * Class InvoiceControllerTest.
 *
 * The invoices' endpoints: drafts with their contract's defaults, the freeze with its number, snapshot and PDF, the
 * lock of frozen invoices, stale writes, the status operations and the list's filters.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Http\Controllers
 */
class InvoiceControllerTest extends TestCase
{
    use ActsInProfiles;
    use RefreshDatabase;

    /**
     * Test that a draft takes its currency, payment terms, item description and rate from the contract, and its
     * account from the client's currency, with the totals computed.
     *
     * @return void
     */
    public function testCreatesADraftWithTheDefaultsOfItsContract(): void
    {
        $profile = $this->createProfile();
        $this->actAs($profile, 'accountant');
        $account = $this->createAccount($profile);
        [$client, $contract] = $this->createClientWithContract($profile);

        $response = $this->postJson($this->profilePath($profile, 'invoices'), [
            'client_id' => $client->id,
            'contract_id' => $contract->id,
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-14',
            'items' => [['quantity' => '86,50']],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.number', null)
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.receiving_account_id', $account->id)
            ->assertJsonPath('data.issued_at', '2026-10-14')
            ->assertJsonPath('data.due_at', '2026-10-28')
            ->assertJsonPath('data.total', '3892.50');
        $this->assertDatabaseHas('invoice_items', [
            'invoice_id' => $response->json('data.id'),
            'description' => 'IT services in accordance to the Service Agreement dated April 13, 2023',
            'quantity' => '86.50',
            'unit_price' => '45.00',
            'amount' => '3892.50',
        ]);
    }

    /**
     * Test that a draft without its client and period is refused with the messages of each field.
     *
     * @return void
     */
    public function testReturns422ForADraftWithoutItsClientAndPeriod(): void
    {
        $profile = $this->createProfile();
        $this->actAs($profile);

        $this->postJson($this->profilePath($profile, 'invoices'), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'client_id' => 'The client id field is required.',
                'period_start' => 'The period start field is required.',
                'period_end' => 'The period end field is required.',
            ]);
        $this->assertDatabaseCount('invoices', 0);
    }

    /**
     * Test that a client of another profile can't be invoiced.
     *
     * @return void
     */
    public function testReturns422ForAClientOfAnotherProfile(): void
    {
        $profile = $this->createProfile();
        $other = $this->createProfile($profile->organization);
        [$client] = $this->createClientWithContract($other);
        $this->actAs($profile);

        $this->postJson($this->profilePath($profile, 'invoices'), [
            'client_id' => $client->id, 'period_start' => '2026-10-01', 'period_end' => '2026-10-14',
        ])->assertUnprocessable()->assertJsonValidationErrors(['client_id' => 'The selected client id is invalid.']);
    }

    /**
     * Test that a viewer can't create an invoice.
     *
     * @return void
     */
    public function testReturns403WhenAViewerCreatesAnInvoice(): void
    {
        $profile = $this->createProfile();
        [$client] = $this->createClientWithContract($profile);
        $this->actAs($profile, 'viewer');

        $this->postJson($this->profilePath($profile, 'invoices'), [
            'client_id' => $client->id, 'period_start' => '2026-10-01', 'period_end' => '2026-10-14',
        ])->assertForbidden();
        $this->assertDatabaseCount('invoices', 0);
    }

    /**
     * Test that freezing a draft gives it its number, locks it with a snapshot, and makes its PDF the primary file.
     *
     * @return void
     */
    public function testFreezesADraftWithItsNumberSnapshotAndPdf(): void
    {
        Storage::fake('local');
        $profile = $this->createProfile();
        $this->actAs($profile);
        $account = $this->createAccount($profile);
        [$client, $contract] = $this->createClientWithContract($profile);
        $id = $this->postJson($this->profilePath($profile, 'invoices'), [
            'client_id' => $client->id, 'contract_id' => $contract->id,
            'period_start' => '2026-10-01', 'period_end' => '2026-10-14', 'items' => [['quantity' => '86.50']],
        ])->json('data.id');

        $response = $this->postJson($this->profilePath($profile, "invoices/$id/freeze"));

        $response->assertOk()
            ->assertJsonPath('message', 'Frozen')
            ->assertJsonPath('data.number', '2026-10-14-1')
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.snapshot.schema_version', 1)
            ->assertJsonPath('data.snapshot.money_format', 'plain_dot')
            ->assertJsonPath('data.snapshot.account.name', $account->name)
            ->assertJsonPath('data.snapshot.totals.total', '3892.50');
        $invoice = $this->inProfile($profile, fn () => Invoice::query()->findOrFail($id));
        $this->assertSame('application/pdf', $invoice->primaryFile->mime_type);
        $this->assertTrue($invoice->primaryFile->isStored());
        $this->get($this->profilePath($profile, "invoices/$id/pdf"))->assertOk()->assertDownload('2026-10-14-1.pdf');
    }

    /**
     * Test that a frozen invoice keeps its amounts (409), while its notes still change.
     *
     * @return void
     */
    public function testReturns409WhenAFrozenInvoiceChangesMoreThanItsNotes(): void
    {
        Storage::fake('local');
        $profile = $this->createProfile();
        $this->actAs($profile);
        $invoice = $this->createFrozenInvoice($profile, $this->createAccount($profile));

        $this->patchJson($this->profilePath($profile, "invoices/{$invoice->id}"), ['period_end' => '2026-10-01'])
            ->assertConflict()
            ->assertJsonPath('message', "The record is locked, so its period_end can't be changed.");
        $this->patchJson($this->profilePath($profile, "invoices/{$invoice->id}"), ['notes' => 'Залишок обіцяли до 28.10'])
            ->assertOk()
            ->assertJsonPath('data.notes', 'Залишок обіцяли до 28.10');
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'period_end' => '2026-09-30']);
    }

    /**
     * Test that an update, which edited an older version of the draft, is refused with 409.
     *
     * @return void
     */
    public function testReturns409ForAStaleUpdate(): void
    {
        $profile = $this->createProfile();
        $this->actAs($profile);
        $this->createAccount($profile);
        [$client] = $this->createClientWithContract($profile);
        $this->travelTo('2026-10-20 10:00:00');
        $draft = $this->postJson($this->profilePath($profile, 'invoices'), [
            'client_id' => $client->id, 'period_start' => '2026-10-01', 'period_end' => '2026-10-14',
            'items' => [['quantity' => '1', 'unit_price' => '100.00', 'description' => 'Work']],
        ])->json('data');
        $this->travelTo('2026-10-20 10:05:00');
        $this->patchJson($this->profilePath($profile, "invoices/{$draft['id']}"), ['notes' => 'Edited by another accountant'])->assertOk();

        $this->withHeader('If-Unmodified-Since', $draft['updated_at'])
            ->patchJson($this->profilePath($profile, "invoices/{$draft['id']}"), ['notes' => 'Mine'])
            ->assertConflict()
            ->assertJsonPath('message', 'The invoice was changed by someone else. Reload it and try again.');
        $this->assertDatabaseHas('invoices', ['id' => $draft['id'], 'notes' => 'Edited by another accountant']);
    }

    /**
     * Test that an invoice with receipts can't return to its draft.
     *
     * @return void
     */
    public function testReturns409WhenUnfreezingAnInvoiceWithReceipts(): void
    {
        Storage::fake('local');
        $profile = $this->createProfile();
        $this->actAs($profile);
        $account = $this->createAccount($profile);
        $this->cacheRates(['USD' => ['2026-10-05' => '42.2297']]);
        $invoice = $this->createFrozenInvoice($profile, $account);
        $this->recordReceipt($profile, $invoice, $account, ['amount' => '100.00']);

        $this->postJson($this->profilePath($profile, "invoices/{$invoice->id}/unfreeze"))
            ->assertConflict()
            ->assertJsonPath('message', "The invoice has receipts, so it can't return to its draft.");
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'ready']);
    }

    /**
     * Test that an invoice is marked paid only once its receipts cover the total, on the last receipt's date.
     *
     * @return void
     */
    public function testMarksAnInvoicePaidOnceItsReceiptsCoverTheTotal(): void
    {
        Storage::fake('local');
        $profile = $this->createProfile();
        $this->actAs($profile);
        $account = $this->createAccount($profile);
        $this->cacheRates(['USD' => ['2026-10-05' => '42.2297', '2026-10-19' => '42.0150']]);
        $invoice = $this->createFrozenInvoice($profile, $account);
        $this->recordReceipt($profile, $invoice, $account, ['amount' => '3100.00']);

        $this->postJson($this->profilePath($profile, "invoices/{$invoice->id}/pay"))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status' => "The receipts cover 3100.00 of 3892.50 USD, so the invoice isn't paid yet."]);

        $this->recordReceipt($profile, $invoice, $account, ['amount' => '792.50', 'received_at' => '2026-10-19']);

        $this->postJson($this->profilePath($profile, "invoices/{$invoice->id}/pay"))
            ->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.paid_at', '2026-10-19')
            ->assertJsonPath('data.paid_amount', '3892.50');
    }

    /**
     * Test that the list narrows by issue periods, mixed, and by status.
     *
     * @return void
     */
    public function testFiltersTheListByPeriodsAndStatus(): void
    {
        Storage::fake('local');
        $profile = $this->createProfile();
        $this->actAs($profile, 'viewer');
        $account = $this->createAccount($profile);
        $september = $this->createFrozenInvoice($profile, $account);
        $october = $this->createFrozenInvoice($profile, $account, ['period_start' => '2026-10-01', 'period_end' => '2026-10-14']);
        $this->createFrozenInvoice($profile, $account, ['period_start' => '2026-05-01', 'period_end' => '2026-05-31']);

        $this->getJson($this->profilePath($profile, 'invoices?filter[period][in]=2026-Q3,2026-10'))
            ->assertOk()
            ->assertJsonPath('data.*.id', [$october->id, $september->id]);
        $this->getJson($this->profilePath($profile, 'invoices?filter[period][in]=2026-13'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['filter.period' => "The period '2026-13' must be a year, a quarter or a month, such as 2026, 2026-Q4 or 2026-10."]);
        $this->getJson($this->profilePath($profile, 'invoices?filter[status][in]=draft'))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
