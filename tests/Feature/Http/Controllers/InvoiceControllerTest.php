<?php

namespace Tests\Feature\Http\Controllers;

use Core\Models\Sequence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PryTax\Models\Invoice;
use PryTax\Services\Invoices\InvoiceWriter;
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
        [$client, $contract] = $this->createClientWithContract($profile, ['abbreviation' => 'BL']);
        $id = $this->postJson($this->profilePath($profile, 'invoices'), [
            'client_id' => $client->id, 'contract_id' => $contract->id,
            'period_start' => '2026-10-01', 'period_end' => '2026-10-14', 'items' => [['quantity' => '86.50']],
        ])->json('data.id');

        $response = $this->postJson($this->profilePath($profile, "invoices/$id/freeze?append=snapshot"));

        $response->assertOk()
            ->assertJsonPath('message', 'Frozen')
            ->assertJsonPath('data.number', 'BL-2026-10-14-1')
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.snapshot.schema_version', 3)
            ->assertJsonPath('data.snapshot.money_format', 'plain_dot')
            ->assertJsonPath('data.snapshot.presentation.payment_purpose', 'Payment for services by the invoice No. BL-2026-10-14-1 from Oct 14, 2026')
            ->assertJsonPath('data.snapshot.account.name', $account->name)
            ->assertJsonPath('data.snapshot.totals.total', '3892.50');
        $invoice = $this->inProfile($profile, fn () => Invoice::query()->findOrFail($id));
        $this->assertSame('application/pdf', $invoice->primaryFile->mime_type);
        $this->assertTrue($invoice->primaryFile->isStored());
        $this->get($this->profilePath($profile, "invoices/$id/pdf"))->assertOk()->assertDownload('BL-2026-10-14-1.pdf');
    }

    /**
     * Test that the snapshot, which copies the tax id and the bank details, is stored encrypted, left out of the
     * audit log and of the list, and shown on request.
     *
     * @return void
     */
    public function testKeepsTheSnapshotEncryptedAndOutOfListsAndTheAuditLog(): void
    {
        Storage::fake('local');
        $profile = $this->createProfile(null, ['tax_id' => '3214704821']);
        $this->actAs($profile, 'viewer');
        $account = $this->createAccount($profile, ['iban' => 'UA213223130000026007233566001']);
        $invoice = $this->createFrozenInvoice($profile, $account);

        $list = $this->getJson($this->profilePath($profile, 'invoices'));
        $shown = $this->getJson($this->profilePath($profile, "invoices/{$invoice->id}?append=snapshot"));

        $list->assertOk()->assertJsonMissingPath('data.0.snapshot');
        $shown->assertOk()
            ->assertJsonPath('data.snapshot.profile.tax_id', '3214704821')
            ->assertJsonPath('data.snapshot.account.iban', 'UA213223130000026007233566001');
        $raw = DB::table('invoices')->where('id', $invoice->id)->value('snapshot');
        $this->assertStringNotContainsString('3214704821', $raw);
        $this->assertStringNotContainsString('UA2132231300', $raw);
        $this->assertFalse(DB::table('audit_logs')->where('changes', 'like', '%3214704821%')->exists());
        $this->assertFalse(DB::table('audit_logs')->where('changes', 'like', '%snapshot%')->exists());
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

        $this->withHeader('If-Unmodified-Since', $invoice->updated_at->toJSON());

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
        $this->withHeader('If-Unmodified-Since', $draft['updated_at'])
            ->patchJson($this->profilePath($profile, "invoices/{$draft['id']}"), ['notes' => 'Edited by another accountant'])
            ->assertOk();

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

    /**
     * Test that an update without If-Unmodified-Since is refused (428), so no write can miss a stale read.
     *
     * @return void
     */
    public function testReturns428ForAnUpdateWithoutIfUnmodifiedSince(): void
    {
        $profile = $this->createProfile();
        $this->actAs($profile);
        $account = $this->createAccount($profile);
        [$client] = $this->createClientWithContract($profile);
        $draft = $this->inProfile($profile, fn () => app(InvoiceWriter::class)->create($profile, [
            'client_id' => $client->id, 'receiving_account_id' => $account->id, 'period_start' => '2026-10-01',
            'period_end' => '2026-10-14', 'items' => [['quantity' => '1', 'unit_price' => '100.00', 'description' => 'Work']],
        ]));

        $this->patchJson($this->profilePath($profile, "invoices/{$draft->id}"), ['notes' => 'Mine'])
            ->assertStatus(428)
            ->assertJsonPath('message', 'If-Unmodified-Since is required: the updated_at of the invoice, which you edited.');
        $this->assertNull($draft->fresh()->notes);
    }

    /**
     * Test that an external invoice keeps its entered total with its items, needs its own number to be frozen, and
     * takes nothing from the counter.
     *
     * @return void
     */
    public function testFreezesAnExternalInvoiceWithItsOwnNumberAndTotal(): void
    {
        Storage::fake('local');
        $profile = $this->createProfile();
        $this->actAs($profile);
        $account = $this->createAccount($profile);
        [$client] = $this->createClientWithContract($profile);
        $draft = $this->postJson($this->profilePath($profile, 'invoices'), [
            'source' => 'external', 'client_id' => $client->id, 'period_start' => '2026-09-01', 'period_end' => '2026-09-30',
            'total' => '5000.00', 'items' => [['quantity' => '1', 'unit_price' => '100.00', 'description' => 'Part of the work']],
        ])->assertCreated()->assertJsonPath('data.total', '5000.00')->json('data');
        $this->post($this->profilePath($profile, "invoices/{$draft['id']}/files"), [
            'file' => UploadedFile::fake()->create('NW-2026-031.pdf', 20, 'application/pdf'), 'primary' => '1',
        ])->assertCreated();

        $this->postJson($this->profilePath($profile, "invoices/{$draft['id']}/freeze"))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['number' => 'An external invoice keeps its own number: type it.']);
        $this->withHeader('If-Unmodified-Since', now()->toJSON())
            ->patchJson($this->profilePath($profile, "invoices/{$draft['id']}"), ['number' => 'NW-2026-031'])
            ->assertOk();
        $this->postJson($this->profilePath($profile, "invoices/{$draft['id']}/freeze"))
            ->assertOk()
            ->assertJsonPath('data.number', 'NW-2026-031')
            ->assertJsonPath('data.total', '5000.00');

        $this->assertSame(1, $this->inProfile($profile, fn () => Sequence::peek($profile, 'invoices:global')));
    }

    /**
     * Test that the primary document of a frozen invoice can't be replaced by an upload (409).
     *
     * @return void
     */
    public function testReturns409WhenAFrozenInvoiceTakesAnotherPrimaryDocument(): void
    {
        Storage::fake('local');
        $profile = $this->createProfile();
        $this->actAs($profile);
        $invoice = $this->createFrozenInvoice($profile, $this->createAccount($profile));
        $primary = $invoice->primary_file_id;

        $this->post($this->profilePath($profile, "invoices/{$invoice->id}/files"), [
            'file' => UploadedFile::fake()->create('other.pdf', 20, 'application/pdf'), 'primary' => '1',
        ])->assertConflict()->assertJsonPath('message', "The primary document of a frozen invoice can't change.");

        $this->assertSame($primary, $invoice->fresh()->primary_file_id);
    }

    /**
     * Test that an invoice, whose receipts keep money, isn't cancelled (409), while one without receipts is.
     *
     * @return void
     */
    public function testReturns409WhenCancellingAnInvoiceWithReceipts(): void
    {
        Storage::fake('local');
        $profile = $this->createProfile();
        $this->actAs($profile);
        $wise = $this->createAccount($profile);
        $this->cacheRates(['USD' => ['2026-10-05' => '42.2297']]);
        $paid = $this->createFrozenInvoice($profile, $wise);
        $this->recordReceipt($profile, $paid, $wise, ['amount' => '100.00']);
        $unpaid = $this->createFrozenInvoice($profile, $wise);

        $this->postJson($this->profilePath($profile, "invoices/{$paid->id}/cancel"))
            ->assertConflict()
            ->assertJsonPath('message', 'The invoice has 100.00 USD of receipts: move them to the invoice, which replaces it, or refund them, before cancelling it.');
        $this->postJson($this->profilePath($profile, "invoices/{$unpaid->id}/cancel"))
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
        $this->assertSame('ready', $paid->fresh()->status->value);
    }

    /**
     * Test that the number of a deleted draft can be given to another invoice.
     *
     * @return void
     */
    public function testFreesTheNumberOfADeletedDraft(): void
    {
        $profile = $this->createProfile();
        $this->actAs($profile);
        $this->createAccount($profile);
        [$client] = $this->createClientWithContract($profile);
        $draft = ['client_id' => $client->id, 'number' => 'INV-77', 'period_start' => '2026-10-01', 'period_end' => '2026-10-14',
            'items' => [['quantity' => '1', 'unit_price' => '100.00', 'description' => 'Work']]];
        $id = $this->postJson($this->profilePath($profile, 'invoices'), $draft)->assertCreated()->json('data.id');

        $this->deleteJson($this->profilePath($profile, "invoices/$id"))->assertOk();

        $this->postJson($this->profilePath($profile, 'invoices'), $draft)->assertCreated()->assertJsonPath('data.number', 'INV-77');
    }

    /**
     * Test that the form of a new invoice starts with the client's and contract's defaults, the expanded
     * description, the rate by the end of the period and the next number.
     *
     * @return void
     */
    public function testFillsTheFormOfANewInvoice(): void
    {
        $profile = $this->createProfile();
        $this->actAs($profile);
        $account = $this->createAccount($profile);
        [$client, $contract] = $this->createClientWithContract($profile, ['abbreviation' => 'BL']);

        $response = $this->getJson($this->profilePath($profile, 'invoices/defaults?' . http_build_query([
            'client_id' => $client->id, 'contract_id' => $contract->id, 'period_start' => '2026-10-01', 'period_end' => '2026-10-14',
        ])));

        $response->assertOk()
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.issued_at', '2026-10-14')
            ->assertJsonPath('data.due_at', '2026-10-28')
            ->assertJsonPath('data.receiving_account_id', $account->id)
            ->assertJsonPath('data.description', $contract->describe())
            ->assertJsonPath('data.hourly_rate', '45.00')
            ->assertJsonPath('data.number_preview', 'BL-2026-10-14-1')
            ->assertJsonPath('data.effective_settings.locale.value', 'en')
            ->assertJsonPath('data.effective_settings.locale.source', 'profile')
            ->assertJsonPath('data.effective_settings.show_contract.value', true)
            ->assertJsonPath('data.effective_settings.show_contract.source', 'default');
    }

    /**
     * Test that a draft without a contract, of a client without a currency, takes the profile's default currency.
     *
     * @return void
     */
    public function testTakesTheProfilesCurrencyForADraftWithoutAContractOrAClientCurrency(): void
    {
        $profile = $this->createProfile(null, ['default_currency' => 'EUR']);
        $this->actAs($profile);
        $account = $this->createAccount($profile, ['currency' => 'EUR']);
        [$client] = $this->createClientWithContract($profile, ['currency' => null]);

        $response = $this->postJson($this->profilePath($profile, 'invoices'), [
            'client_id' => $client->id, 'period_start' => '2026-10-01', 'period_end' => '2026-10-14',
            'items' => [['quantity' => '1', 'unit_price' => '100.00', 'description' => 'Work']],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.currency', 'EUR')
            ->assertJsonPath('data.receiving_account_id', $account->id);
    }

    /**
     * Test that the freeze writes the document settings, as the client resolves them, into a snapshot of version 3:
     * the parties' names in the client's language, the payment purpose with the final number, no act note, when it is
     * off, the style and the note with its kind, and no footer note; a later change of the client reaches its drafts,
     * but not the frozen invoice.
     *
     * @return void
     */
    public function testFreezesTheDocumentSettingsOfTheClientIntoTheSnapshot(): void
    {
        Storage::fake('local');
        $profile = $this->createProfile(null, ['full_name' => 'Мирчук Микола', 'full_name_en' => 'Myrchuk Mykola']);
        $this->actAs($profile);
        $this->createAccount($profile);
        [$client, $contract] = $this->createClientWithContract($profile, [
            'abbreviation' => 'BL', 'invoice_locale' => 'uk', 'act_note_enabled' => false, 'invoice_style' => 'vivid',
            'payment_note_enabled' => true, 'payment_note' => 'Вкажіть номер інвойсу.', 'other_note_enabled' => true, 'other_note' => 'Дякуємо.',
        ]);
        $draft = fn () => $this->postJson($this->profilePath($profile, 'invoices'), [
            'client_id' => $client->id, 'contract_id' => $contract->id,
            'period_start' => '2026-10-01', 'period_end' => '2026-10-14', 'items' => [['quantity' => '1']],
        ])->json('data.id');
        $frozen = $draft();
        $this->postJson($this->profilePath($profile, "invoices/$frozen/freeze"))->assertOk();
        $open = $draft();

        $this->patchJson($this->profilePath($profile, "clients/{$client->id}"), ['act_note_enabled' => true, 'invoice_style' => 'colored'])->assertOk();

        $snapshot = $this->getJson($this->profilePath($profile, "invoices/$frozen?append=snapshot"))
            ->assertOk()
            ->assertJsonPath('data.snapshot.schema_version', 3)
            ->assertJsonPath('data.snapshot.locale', 'uk')
            ->assertJsonPath('data.snapshot.profile.name', 'Мирчук Микола')
            ->assertJsonPath('data.snapshot.presentation', [
                'style' => 'vivid',
                'payment_purpose' => 'Оплата за послуги згідно з інвойсом № BL-2026-10-14-1 від 14 жовтня 2026',
                'act_note' => null,
                'show_contract' => true,
                'payment_note' => 'Вкажіть номер інвойсу.',
                'other_note' => 'Дякуємо.',
            ])
            ->json('data.snapshot');
        $this->assertArrayNotHasKey('footer_note', $snapshot);
        $this->getJson($this->profilePath($profile, "invoices/$open?append=effective_settings"))
            ->assertOk()
            ->assertJsonPath('data.effective_settings.act_note_enabled.value', true)
            ->assertJsonPath('data.effective_settings.act_note_enabled.source', 'client')
            ->assertJsonPath('data.effective_settings.style.value', 'colored');
    }

    /**
     * Test that a payment purpose, which needs the number of a contract without one, stops the freeze with 422,
     * naming the level of its template, and leaves the draft without a number.
     *
     * @return void
     */
    public function testReturns422WhenThePaymentPurposeNeedsANumberTheContractLacks(): void
    {
        $profile = $this->createProfile();
        $this->actAs($profile);
        $this->createAccount($profile);
        [$client, $contract] = $this->createClientWithContract($profile, ['payment_purpose_template' => 'Under contract No. {contract.number}']);
        $id = $this->postJson($this->profilePath($profile, 'invoices'), [
            'client_id' => $client->id, 'contract_id' => $contract->id,
            'period_start' => '2026-10-01', 'period_end' => '2026-10-14', 'items' => [['quantity' => '1']],
        ])->json('data.id');

        $response = $this->postJson($this->profilePath($profile, "invoices/$id/freeze"));

        $response->assertUnprocessable()->assertJsonValidationErrors([
            'payment_purpose_template' => "The payment purpose uses {contract.number}, but the invoice's contract has no number. The template is the client's.",
        ]);
        $this->assertDatabaseHas('invoices', ['id' => $id, 'status' => 'draft', 'number' => null]);
    }

    /**
     * Test that a draft's unknown style, or its note switched on without a text, is refused with 422.
     *
     * @return void
     */
    public function testReturns422ForAnUnknownStyleOrANoteWithoutItsText(): void
    {
        $profile = $this->createProfile();
        $this->actAs($profile);
        [$client] = $this->createClientWithContract($profile);

        $response = $this->postJson($this->profilePath($profile, 'invoices'), [
            'client_id' => $client->id, 'period_start' => '2026-10-01', 'period_end' => '2026-10-14',
            'style' => 'glitter', 'payment_note_enabled' => true, 'payment_note' => '  ',
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors([
            'style' => 'The selected style is invalid.',
            'payment_note' => 'Enter the note text.',
        ]);
        $this->assertDatabaseCount('invoices', 0);
    }

    /**
     * Test that the style preview renders the client's latest invoice in the asked style and language as a PDF, for a
     * viewer too, and saves nothing: the draft keeps its own settings and gets no number or file.
     *
     * @return void
     */
    public function testPreviewsAStyleOnTheClientsLatestInvoiceWithoutSavingIt(): void
    {
        Storage::fake('local');
        $profile = $this->createProfile();
        $this->actAs($profile);
        $this->createAccount($profile);
        [$client, $contract] = $this->createClientWithContract($profile);
        $id = $this->postJson($this->profilePath($profile, 'invoices'), [
            'client_id' => $client->id, 'contract_id' => $contract->id,
            'period_start' => '2026-10-01', 'period_end' => '2026-10-14', 'items' => [['quantity' => '1']],
        ])->json('data.id');
        $this->actAs($profile, 'viewer');

        $response = $this->get($this->profilePath($profile, "invoices/style-preview?style=colored&locale=uk&client_id={$client->id}"));

        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertDatabaseHas('invoices', ['id' => $id, 'style' => null, 'locale' => null, 'number' => null]);
        $this->assertDatabaseCount('files', 0);
    }

    /**
     * Test that the style preview of a profile without any invoice renders a demo invoice.
     *
     * @return void
     */
    public function testPreviewsAStyleOnADemoInvoiceWithoutAnyInvoice(): void
    {
        $profile = $this->createProfile();
        $this->actAs($profile);

        $response = $this->get($this->profilePath($profile, 'invoices/style-preview?style=andriichello_carpathians'));

        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    /**
     * Test that the style preview refuses an unknown style and another profile's client with 422.
     *
     * @return void
     */
    public function testReturns422ForAPreviewOfAnUnknownStyleOrAnotherProfilesClient(): void
    {
        $profile = $this->createProfile();
        [$stranger] = $this->createClientWithContract($this->createProfile($profile->organization));
        $this->actAs($profile);

        $this->getJson($this->profilePath($profile, "invoices/style-preview?style=glitter&client_id={$stranger->id}"))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['style', 'client_id']);
    }

    /**
     * Test that a draft's payment purpose with a placeholder outside of the whitelist is refused with 422.
     *
     * @return void
     */
    public function testReturns422ForAPaymentPurposeWithAnUnknownPlaceholder(): void
    {
        $profile = $this->createProfile();
        $this->actAs($profile);
        [$client] = $this->createClientWithContract($profile);

        $response = $this->postJson($this->profilePath($profile, 'invoices'), [
            'client_id' => $client->id, 'period_start' => '2026-10-01', 'period_end' => '2026-10-14',
            'payment_purpose_template' => 'Invoice {invoice.number} of {invoice.total}',
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors([
            'payment_purpose_template' => 'The payment purpose has unknown placeholders: {invoice.total}.',
        ]);
        $this->assertDatabaseCount('invoices', 0);
    }

    /**
     * Test that a draft shows its payment purpose as it would be frozen, with the warnings of a reference in euros,
     * which SWIFT can't carry, and of a currency other than the contract's; and that null clears its own setting.
     *
     * @return void
     */
    public function testShowsTheDraftsPaymentPurposeWithItsWarnings(): void
    {
        $profile = $this->createProfile();
        $this->actAs($profile);
        $this->createAccount($profile, ['currency' => 'EUR']);
        [$client, $contract] = $this->createClientWithContract($profile, ['abbreviation' => 'BL']);
        $draft = $this->postJson($this->profilePath($profile, 'invoices'), [
            'client_id' => $client->id, 'contract_id' => $contract->id, 'currency' => 'EUR', 'locale' => 'uk',
            'period_start' => '2026-10-01', 'period_end' => '2026-10-14',
            'items' => [['quantity' => '1', 'unit_price' => '100.00']],
        ])->json('data');

        $shown = $this->getJson($this->profilePath($profile, "invoices/{$draft['id']}?append=payment_purpose_preview,warnings"));
        $cleared = $this->withHeader('If-Unmodified-Since', $draft['updated_at'])
            ->patchJson($this->profilePath($profile, "invoices/{$draft['id']}?append=effective_settings"), ['locale' => null]);

        $shown->assertOk()
            ->assertJsonPath('data.payment_purpose_preview', 'Оплата за послуги згідно з інвойсом № BL-2026-10-14-1 від 14 жовтня 2026')
            ->assertJsonPath('data.warnings', ['payment_purpose_charset', 'currency_differs_from_contract']);
        $cleared->assertOk()
            ->assertJsonPath('data.locale', null)
            ->assertJsonPath('data.effective_settings.locale.value', 'en')
            ->assertJsonPath('data.effective_settings.locale.source', 'profile');
        $this->assertDatabaseHas('invoices', ['id' => $draft['id'], 'locale' => null]);
    }

    /**
     * Test that the open invoices' totals tell what is left to pay per currency, and how much of it is overdue.
     *
     * @return void
     */
    public function testTotalsWhatTheOpenInvoicesLeaveToPay(): void
    {
        Storage::fake('local');
        $this->travelTo('2026-10-20 10:00:00');
        $profile = $this->createProfile();
        $this->actAs($profile, 'viewer');
        $wise = $this->createAccount($profile);
        $this->cacheRates(['USD' => ['2026-10-05' => '42.2297']]);
        $partly = $this->createFrozenInvoice($profile, $wise);
        $this->recordReceipt($profile, $partly, $wise, ['amount' => '3100.00']);
        $this->createFrozenInvoice($profile, $wise, ['period_start' => '2026-10-01', 'period_end' => '2026-10-14', 'due_at' => '2026-11-01']);

        $response = $this->getJson($this->profilePath($profile, 'invoices/outstanding'));

        $response->assertOk()
            ->assertJsonPath('data.0.currency', 'USD')
            ->assertJsonPath('data.0.count', 2)
            ->assertJsonPath('data.0.remaining', '4685.00')
            ->assertJsonPath('data.0.overdue_count', 1)
            ->assertJsonPath('data.0.overdue', '792.50');
    }

    /**
     * Test that the filters are offered the years, currencies, statuses and clients of the profile's invoices.
     *
     * @return void
     */
    public function testOffersTheFiltersOfTheProfilesInvoices(): void
    {
        Storage::fake('local');
        $profile = $this->createProfile();
        $this->actAs($profile, 'viewer');
        $wise = $this->createAccount($profile);
        $invoice = $this->createFrozenInvoice($profile, $wise);

        $this->getJson($this->profilePath($profile, 'invoices/facets'))
            ->assertOk()
            ->assertJsonPath('data.years', [['year' => 2026, 'count' => 1]])
            ->assertJsonPath('data.currencies', [['currency' => 'USD', 'count' => 1]])
            ->assertJsonPath('data.statuses', [['status' => 'ready', 'count' => 1]])
            ->assertJsonPath('data.clients', [['client_id' => $invoice->client_id, 'name' => 'Brightline Labs LLC', 'count' => 1]]);
    }

    /**
     * Test that the invoices with a file of the SHA-256 are found, before an external PDF's invoice is created.
     *
     * @return void
     */
    public function testFindsTheInvoicesWithAFileOfTheSameHash(): void
    {
        Storage::fake('local');
        $profile = $this->createProfile();
        $this->actAs($profile);
        $invoice = $this->createFrozenInvoice($profile, $this->createAccount($profile));
        $pdf = UploadedFile::fake()->createWithContent('NW-2026-031.pdf', '%PDF-1.4 external invoice');
        $this->post($this->profilePath($profile, "invoices/{$invoice->id}/files"), ['file' => $pdf])->assertCreated();

        $this->getJson($this->profilePath($profile, 'invoices/duplicates?sha256=' . hash('sha256', '%PDF-1.4 external invoice')))
            ->assertOk()
            ->assertJsonPath('data.0.invoice_id', $invoice->id)
            ->assertJsonPath('data.0.number', $invoice->number);
        $this->getJson($this->profilePath($profile, 'invoices/duplicates?sha256=' . str_repeat('a', 64)))
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    /**
     * Test that a client's counter, listed with the client's abbreviation, moves forward, such as after an import, but
     * never back.
     *
     * @return void
     */
    public function testMovesACounterForwardOnly(): void
    {
        Storage::fake('local');
        $profile = $this->createProfile();
        $this->actAs($profile);
        [$client, $contract] = $this->createClientWithContract($profile, ['abbreviation' => 'BL']);
        $this->createFrozenInvoice($profile, $this->createAccount($profile), ['client_id' => $client->id, 'contract_id' => $contract->id]);
        $scope = 'client:' . $client->id;

        $this->patchJson($this->profilePath($profile, 'invoices/counters'), ['scope_key' => $scope, 'next_number' => 13])
            ->assertOk()
            ->assertJsonPath('data.0.last_number', 12)
            ->assertJsonPath('data.0.client_abbreviation', 'BL');
        $this->patchJson($this->profilePath($profile, 'invoices/counters'), ['scope_key' => $scope, 'next_number' => 5])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['next_number' => 'The counter is at 13 already, and never goes back.']);
    }

    /**
     * Test that the list is filtered by an issue range, clients and currencies, newest first, and paged.
     *
     * @return void
     */
    public function testFiltersTheListByRangeClientAndCurrencyAndPagesIt(): void
    {
        Storage::fake('local');
        $profile = $this->createProfile();
        $this->actAs($profile, 'viewer');
        $wise = $this->createAccount($profile);
        $euros = $this->createAccount($profile, ['name' => 'Wise EUR', 'currency' => 'EUR']);
        $september = $this->createFrozenInvoice($profile, $wise);
        $october = $this->createFrozenInvoice($profile, $wise, ['client_id' => $september->client_id, 'contract_id' => $september->contract_id,
            'period_start' => '2026-10-01', 'period_end' => '2026-10-14']);
        $other = $this->createFrozenInvoice($profile, $wise, ['period_start' => '2026-10-01', 'period_end' => '2026-10-10']);
        $inEuros = $this->inProfile($profile, fn () => Invoice::factory()->create([
            'profile_id' => $profile->id, 'client_id' => $september->client_id, 'currency' => 'EUR', 'receiving_account_id' => $euros->id,
        ]));

        $this->getJson($this->profilePath($profile, 'invoices?filter[issued_at][gte]=2026-10-01&filter[issued_at][lte]=2026-10-31'))
            ->assertOk()
            ->assertJsonPath('data.*.id', [$inEuros->id, $october->id, $other->id]);
        $this->getJson($this->profilePath($profile, "invoices?filter[client_id][in]={$september->client_id}&filter[currency][in]=USD"))
            ->assertOk()
            ->assertJsonPath('data.*.id', [$october->id, $september->id]);
        $this->getJson($this->profilePath($profile, 'invoices?page[size]=2&page[number]=2'))
            ->assertOk()
            ->assertJsonPath('data.*.id', [$other->id, $september->id])
            ->assertJsonPath('meta.total', 4);
    }
}
