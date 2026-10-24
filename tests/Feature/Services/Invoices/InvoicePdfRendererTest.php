<?php

namespace Tests\Feature\Services\Invoices;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PryTax\Models\Invoice;
use PryTax\Services\Invoices\InvoicePdfRenderer;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;

/**
 * Class InvoicePdfRendererTest.
 *
 * The invoice's document is rendered from its snapshot only (design.md §4, §10): in the money format, which was in
 * force when it was frozen, so a later change of the setting, of the profile or of the account renders the same. A
 * snapshot of version 1 keeps template v1; one of version 2 takes template v2 (invoice.md), in its language, with its
 * contract section, payment purpose and act note as they were resolved on freeze.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Services\Invoices
 */
class InvoicePdfRendererTest extends TestCase
{
    use ActsInProfiles;
    use RefreshDatabase;

    /**
     * Snapshots, whose contract section isn't printed.
     *
     * @return array<string, array{0: array}>
     */
    public static function snapshotsWithoutTheContract(): array
    {
        return [
            'the section hidden' => [['presentation' => ['show_contract' => false]]],
            'no contract' => [['contract' => null]],
        ];
    }

    /**
     * Test that the template renders the snapshot's amounts in the snapshot's money format, after the setting and the
     * account changed.
     *
     * @return void
     */
    public function testRendersTheSnapshotInItsOwnMoneyFormat(): void
    {
        Storage::fake('local');
        $profile = $this->createProfile();
        $this->inProfile($profile, fn () => $profile->updateSettings(['invoice.money_format' => 'comma_dot']));
        $account = $this->createAccount($profile, ['bank_name' => 'Wise']);
        $invoice = $this->createFrozenInvoice($profile, $account);
        $this->inProfile($profile, fn () => $profile->updateSettings(['invoice.money_format' => 'plain_dot']));
        $account->forceFill(['bank_name' => 'Renamed Bank'])->save();

        $html = $this->inProfile($profile, fn () => app(InvoicePdfRenderer::class)->html($invoice->fresh()));

        $this->assertSame('comma_dot', $invoice->fresh()->snapshot['money_format']);
        $this->assertStringContainsString('3,892.50', $html);
        $this->assertStringContainsString('Wise', $html);
        $this->assertStringNotContainsString('Renamed Bank', $html);
    }

    /**
     * Test that a new render takes template v2, and records it with the file.
     *
     * @return void
     */
    public function testRecordsTemplateV2WithANewRender(): void
    {
        Storage::fake('local');
        $profile = $this->createProfile();
        $invoice = $this->createFrozenInvoice($profile, $this->createAccount($profile));

        $metadata = $this->inProfile($profile, fn () => $invoice->fresh()->primaryFile->metadata);

        $this->assertSame('v2', $metadata['template_version']);
        $this->assertSame(2, $metadata['schema_version']);
    }

    /**
     * Test that an invoice frozen with a snapshot of version 1 renders again with template v1, and records it.
     *
     * @return void
     */
    public function testRendersASnapshotOfVersion1WithTemplateV1(): void
    {
        Storage::fake('local');
        $profile = $this->createProfile();
        $invoice = $this->createFrozenInvoice($profile, $this->createAccount($profile));
        DB::table('invoices')->where('id', $invoice->id)->update(['snapshot' => Crypt::encryptString(json_encode($this->snapshotOfVersion1()))]);

        [$html, $file] = $this->inProfile($profile, fn () => [
            app(InvoicePdfRenderer::class)->html($invoice->fresh()),
            app(InvoicePdfRenderer::class)->attachGenerated($invoice->fresh()),
        ]);

        $this->assertStringContainsString('Service period:', $html);
        $this->assertStringContainsString('Individual Entrepreneur Myrchuk Mykola', $html);
        $this->assertSame('v1', $file->metadata['template_version']);
    }

    /**
     * Test that the parties, the payment details and the payment purpose print in the layout of template v2: the
     * ФОП's name apart from its role, the payment details without colons, the purpose as it was frozen, and none of
     * the labels of template v1.
     *
     * @return void
     */
    public function testPrintsTheDocumentInTheLayoutOfTemplateV2(): void
    {
        $html = $this->html();

        $this->assertStringContainsString('<div class="party-name-lg">Myrchuk Mykola</div>', $html);
        $this->assertStringContainsString('<div class="label role">Individual Entrepreneur</div>', $html);
        $this->assertStringContainsString('<span class="party-name">Service Agreement No. 109-23</span>', $html);
        $this->assertStringContainsString('<span class="party-details">dated Sep 1, 2026</span>', $html);
        $this->assertStringContainsString('<div class="period-dates">Oct 1, 2026 – Oct 31, 2026</div>', $html);
        $this->assertStringContainsString('pt">Account number</td>', $html);
        $this->assertStringContainsString('<div class="purpose-text">Payment for IT services by the invoice No. BRIT-2026-10-31-2 from Oct 31, 2026</div>', $html);
        $this->assertStringContainsString('<td class="num mono">30.00 fixed</td>', $html);
        $this->assertStringContainsString('5213.00 USD', $html);
        $this->assertStringNotContainsString('Account number:', $html);
        $this->assertStringNotContainsString('>From<', $html);
        $this->assertStringNotContainsString('Service period', $html);
        $this->assertStringNotContainsString('Contract:', $html);
        $this->assertStringNotContainsString('Payment reference', $html);
    }

    /**
     * Test that the contract section is left out, when it is hidden or the invoice has no contract.
     *
     * @param array $changes
     *
     * @return void
     */
    #[DataProvider('snapshotsWithoutTheContract')]
    public function testLeavesOutTheContractSection(array $changes): void
    {
        $html = $this->html($changes);

        $this->assertStringNotContainsString('>Contract<', $html);
        $this->assertStringNotContainsString('<span class="party-name">', $html);
    }

    /**
     * Test that the act note is printed in the card, after the payment purpose.
     *
     * @return void
     */
    public function testPrintsTheActNoteAfterThePaymentPurposeInTheCard(): void
    {
        $html = $this->html();

        $card = substr($html, (int) strpos($html, '<div class="card">'));
        $purpose = strpos($card, 'Payment for IT services');
        $note = strpos($card, '<div class="purpose-text">Payment of this invoice confirms');
        $this->assertNotFalse($purpose);
        $this->assertNotFalse($note);
        $this->assertGreaterThan($purpose, $note);
        $this->assertStringContainsString('<div class="label">Act of acceptance</div>', $card);
    }

    /**
     * Test that the act of acceptance and its rule are left out, when the note is off.
     *
     * @return void
     */
    public function testLeavesOutTheActOfAcceptanceWhenItsNoteIsOff(): void
    {
        $html = $this->html(['presentation' => ['act_note' => null]]);

        $this->assertStringNotContainsString('Act of acceptance', $html);
        $this->assertStringNotContainsString('<div class="card-section">', $html);
    }

    /**
     * Test that a Ukrainian invoice has Ukrainian labels, units and dates.
     *
     * @return void
     */
    public function testPrintsTheLabelsUnitsAndDatesInUkrainian(): void
    {
        $html = $this->html(['locale' => 'uk']);

        $this->assertStringContainsString('<div class="label role">фізична особа-підприємець</div>', $html);
        $this->assertStringContainsString('<div class="label">Платник</div>', $html);
        $this->assertStringContainsString('<div class="label">Платіжні реквізити</div>', $html);
        $this->assertStringContainsString('<div class="label">Акт приймання-передачі</div>', $html);
        $this->assertStringContainsString('<div class="period-dates">01.10.2026 – 31.10.2026</div>', $html);
        $this->assertStringContainsString('<span class="party-name">Service Agreement № 109-23</span>', $html);
        $this->assertStringContainsString('РНОКПП: 1234500592', $html);
        $this->assertStringContainsString('<td class="num mono">20.00 год</td>', $html);
        $this->assertStringContainsString('pt">Номер рахунку</td>', $html);
    }

    /**
     * Test that the items' columns fit their longest values, at 4.515 pt a character of DejaVu Sans Mono at 7.5 pt,
     * or the longest word of their headers.
     *
     * @return void
     */
    public function testFitsTheItemsColumnsToTheirLongestValues(): void
    {
        $label = fn (string $key, array $replace = []) => trans("invoice.labels.$key", $replace, 'en');
        $items = [
            ['position' => '9', 'qty' => '1.00 h', 'price' => '40.00', 'amount' => '40.00'],
            ['position' => '10', 'qty' => '30.00 fixed', 'price' => '1250.00', 'amount' => '123456.00'],
        ];

        $widths = app(InvoicePdfRenderer::class)->itemsColWidths($items, 'USD', $label);

        // "10" is 9.03 pt, 11 characters 49.665 pt, 7 ones 31.605 pt, 9 ones 40.635 pt, each rounded up to 0.1 pt
        $this->assertSame(['no' => 9.1, 'qty' => 49.7, 'price' => 31.7, 'amount' => 40.7], $widths);
    }

    /**
     * Test that the PDF embeds IBM Plex Mono SemiBold, the face of the invoice's number.
     *
     * @return void
     */
    public function testEmbedsThePlexMonoSemiBoldFaceOfTheNumber(): void
    {
        $invoice = new Invoice;
        $invoice->forceFill(['snapshot' => $this->snapshot()]);

        $pdf = app(InvoicePdfRenderer::class)->render($invoice);

        $this->assertMatchesRegularExpression('#/BaseFont /[A-Z]+\+IBMPlexMono-SmBld#', $pdf);
    }

    /**
     * Render the HTML of template v2 for the snapshot with the changes.
     *
     * @param array $changes
     *
     * @return string
     */
    private function html(array $changes = []): string
    {
        $invoice = new Invoice;
        $invoice->forceFill(['snapshot' => array_replace_recursive($this->snapshot(), $changes)]);

        return app(InvoicePdfRenderer::class)->html($invoice);
    }

    /**
     * A snapshot of version 2 of the invoice BRIT-2026-10-31-2: three items, 5213.00 USD, paid to Payoneer.
     *
     * @return array
     */
    private function snapshot(): array
    {
        $item = fn (int $position, string $unit, string $quantity, string $price, string $amount) => [
            'position' => $position, 'project_code' => 'CRONE', 'charge_type' => 'Billable',
            'description' => 'IT services in accordance to the Service Agreement #109-23 dated September 1, 2026',
            'unit' => $unit, 'quantity' => $quantity, 'unit_price' => $price, 'amount' => $amount,
        ];

        return [
            'schema_version' => 2,
            'money_format' => 'plain_dot',
            'locale' => 'en',
            'profile' => [
                'name' => 'Myrchuk Mykola', 'address' => 'Kapushanska St, 42, 15, Uzhhorod, Ukraine', 'tax_id' => '1234500592',
                'tax_id_kind' => 'rnokpp', 'email' => 'mykola@example.com', 'phone' => '+3801237654', 'signature_file_id' => null,
            ],
            'client' => [
                'name' => 'Bring IT LLC', 'country' => 'US', 'vat_number' => null, 'tax_id' => null,
                'address' => ['lines' => ['500 Market St, Suite 200'], 'city' => 'New Jersey', 'zip' => '25902'],
            ],
            'contract' => ['name' => 'Service Agreement', 'number' => '109-23', 'subject' => 'IT services', 'started_at' => '2026-09-01'],
            'presentation' => [
                'show_contract' => true,
                'payment_purpose' => 'Payment for IT services by the invoice No. BRIT-2026-10-31-2 from Oct 31, 2026',
                'act_note' => 'Payment of this invoice confirms the successful delivery of services and serves as the Act of Acceptance.',
            ],
            'account' => [
                'currency' => 'USD', 'holder_name' => 'Myrchuk Mykola', 'bank_name' => 'Payoneer', 'iban' => null,
                'account_number' => '70450600001112233', 'swift' => 'CITIUS33',
                'details' => [['label' => 'Routing (ABA)', 'value' => '022200101']],
            ],
            'invoice' => [
                'number' => 'BRIT-2026-10-31-2', 'period_start' => '2026-10-01', 'period_end' => '2026-10-31',
                'issued_at' => '2026-10-31', 'due_at' => '2026-11-14', 'currency' => 'USD',
            ],
            'items' => [
                $item(1, 'hour', '20.00', '40.00', '800.00'),
                $item(2, 'fixed', '30.00', '25.00', '750.00'),
                $item(3, 'item', '37.00', '99.00', '3663.00'),
            ],
            'totals' => ['subtotal' => '5213.00', 'adjustment_amount' => '0.00', 'adjustment_label' => null, 'total' => '5213.00'],
            'footer_note' => 'Some small note for Bring IT LLC.',
        ];
    }

    /**
     * A snapshot of version 1, as invoices frozen before template v2 have it.
     *
     * @return array
     */
    private function snapshotOfVersion1(): array
    {
        $snapshot = $this->snapshot();
        unset($snapshot['presentation']);
        $snapshot['schema_version'] = 1;
        $snapshot['profile'] = [
            'full_name' => 'Мирчук Микола', 'full_name_en' => 'Myrchuk Mykola', 'tax_id' => '1234500592', 'tax_id_kind' => 'rnokpp',
            'address' => 'Ужгород', 'address_en' => 'Uzhhorod', 'email' => null, 'phone' => null, 'signature_file_id' => null,
        ];
        $snapshot['client']['legal_name'] = 'Bring IT LLC';

        return $snapshot;
    }
}
