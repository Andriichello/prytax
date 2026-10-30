<?php

namespace Tests\Feature\Services\Invoices;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PryTax\Enums\InvoiceStyle;
use PryTax\Models\Invoice;
use PryTax\Services\Invoices\InvoicePdfRenderer;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;

/**
 * Class InvoicePdfRendererTest.
 *
 * The invoice's document is rendered from its snapshot only (design.md §4, §10): in the money format, which was in
 * force when it was frozen, so a later change of the setting, of the profile or of the account renders the same. A
 * snapshot of version 1 keeps template v1; a newer one takes template v3 (docs/specs/invoice.md), in its language and
 * style, with its contract section, payment purpose, act note and notes as they were resolved on freeze. A snapshot of
 * version 2, which never left development, renders with v3 as `outlined`.
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
     * Styles with the class of their PDF's root, which is boxed in every style but Simplified.
     *
     * @return array<string, array{0: InvoiceStyle, 1: string}>
     */
    public static function stylesWithTheirRootClass(): array
    {
        return collect(InvoiceStyle::cases())
            ->mapWithKeys(fn (InvoiceStyle $style) => [$style->value => [
                $style,
                'inv ' . $style->cssClass() . ($style === InvoiceStyle::Simplified ? '' : ' boxed'),
            ]])
            ->all();
    }

    /**
     * Names with their monograms.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function namesWithTheirMonograms(): array
    {
        return [
            'surname and name' => ['Prykhodko Andrii', 'AP'],
            'in Cyrillic' => ['Приходько Андрій', 'АП'],
            'with a patronymic' => ['Бондар Іван Петрович', 'ІБ'],
            'one word' => ['madonna', 'MA'],
        ];
    }

    /**
     * Styles with the font beyond DejaVu, which their PDF embeds.
     *
     * @return array<string, array{0: InvoiceStyle, 1: string}>
     */
    public static function stylesWithTheirFonts(): array
    {
        return [
            'andriichello' => [InvoiceStyle::Andriichello, 'IBMPlexMono'],
            'freeform' => [InvoiceStyle::Freeform, 'Unbounded-'],
            'outlined' => [InvoiceStyle::Outlined, 'IBMPlexMono-SmBld'],
        ];
    }

    /**
     * Languages of an invoice with the word, which marks a draft's preview in each.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function languagesWithTheDraftMark(): array
    {
        return [
            'en' => ['en', 'DRAFT'],
            'uk' => ['uk', 'ЧЕРНЕТКА'],
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
     * Test that a new render takes template v3, and records it with the file.
     *
     * @return void
     */
    public function testRecordsTemplateV3WithANewRender(): void
    {
        Storage::fake('local');
        $profile = $this->createProfile();
        $invoice = $this->createFrozenInvoice($profile, $this->createAccount($profile));

        $metadata = $this->inProfile($profile, fn () => $invoice->fresh()->primaryFile->metadata);

        $this->assertSame('v3', $metadata['template_version']);
        $this->assertSame(3, $metadata['schema_version']);
    }

    /**
     * Test that the PDF draws the signature of the invoice's own profile, and none, when the snapshot names a file of
     * another profile (a snapshot restored from a backup, backups.md §4.2).
     *
     * @return void
     */
    public function testDrawsOnlyASignatureOfTheInvoicesOwnProfile(): void
    {
        Storage::fake('local');
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $profile = $this->createProfile();
        $other = $this->createProfile($profile->organization);
        $this->inProfile($profile, fn () => $profile->attachContents($png, 'signature.png', $profile::SIGNATURE_COLLECTION));
        $foreign = $this->inProfile($other, fn () => $other->attachContents($png, 'signature.png', $other::SIGNATURE_COLLECTION));
        $invoice = $this->createFrozenInvoice($profile, $this->createAccount($profile))->fresh();
        $renderer = app(InvoicePdfRenderer::class);

        $this->assertStringContainsString('data:image/png;base64,', $this->inProfile($profile, fn () => $renderer->html($invoice)));

        $invoice->snapshot = array_replace_recursive($invoice->snapshot, ['profile' => ['signature_file_id' => $foreign->id]]);

        $this->assertStringNotContainsString('data:image/png', $this->inProfile($profile, fn () => $renderer->html($invoice)));
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
     * Test that a file of template v2, which never left development, renders again with template v3.
     *
     * @return void
     */
    public function testRendersAFileOfTemplateV2AgainWithTemplateV3(): void
    {
        Storage::fake('local');
        $profile = $this->createProfile();
        $invoice = $this->createFrozenInvoice($profile, $this->createAccount($profile));
        $this->inProfile($profile, fn () => $invoice->fresh()->primaryFile->forceFill(['metadata' => ['template_version' => 'v2', 'schema_version' => 2]])->save());

        $template = $this->inProfile($profile, fn () => app(InvoicePdfRenderer::class)->templateFor($invoice->fresh()));

        $this->assertSame('v3', $template);
    }

    /**
     * Test that a snapshot of version 2 renders as `outlined`, with its footer note as the other note on the right of
     * the payment card.
     *
     * @return void
     */
    public function testRendersASnapshotOfVersion2AsOutlinedWithItsFooterNoteAsTheOtherNote(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['schema_version'] = 2;
        unset($snapshot['presentation']['style'], $snapshot['presentation']['payment_note'], $snapshot['presentation']['other_note']);
        $snapshot['footer_note'] = 'Some small note for Bring IT LLC.';

        $html = $this->html(replace: $snapshot);

        $this->assertStringContainsString('<div class="inv s-outlined boxed">', $html);
        $this->assertStringContainsString('<div class="lbl">Other notes</div>', $html);
        $this->assertStringContainsString('Some small note for Bring IT LLC.', $html);
    }

    /**
     * Test that the head, the strip and the payment card print in the layout of template v3: the ФОП's name apart
     * from its role, its contacts on one line, the period on two lines, the purpose as the last of the payment details,
     * names without colons, and no note under the card.
     *
     * @return void
     */
    public function testPrintsTheDocumentInTheLayoutOfTemplateV3(): void
    {
        $html = $this->html();

        $this->assertStringContainsString('<div class="party-name party-name-lg">Myrchuk Mykola</div>', $html);
        $this->assertStringContainsString('<div class="label role">Individual Entrepreneur</div>', $html);
        $this->assertStringContainsString(
            '<span class="ci">Tax ID: 1234500592</span><span class="sp"> | </span><span class="ci">mykola@example.com</span><span class="sp"> | </span><span class="ci">+3801237654</span>',
            $html,
        );
        $this->assertStringContainsString('<span class="nw">Oct 1, 2026 –</span><br><span class="nw">Oct 31, 2026</span>', $html);
        $this->assertStringContainsString('<span class="party-name">Service Agreement No. 109-23</span>', $html);
        $this->assertStringContainsString('<td class="k">Account number</td>', $html);
        $this->assertMatchesRegularExpression(
            '#<tr class="purpose-row">\s*<td class="k">Purpose</td>\s*<td class="v">Payment for IT services by the invoice No\. BRIT-2026-10-31-2 from Oct 31, 2026</td>\s*</tr>\s*</table>#',
            $html,
        );
        $this->assertStringContainsString('<td class="r mono">30.00 fixed</td>', $html);
        $this->assertStringContainsString('5213.00 USD', $html);
        $this->assertStringNotContainsString('Account number:', $html);
        $this->assertStringNotContainsString('footer-note', $html);
        $this->assertStringNotContainsString('Payment reference', $html);
    }

    /**
     * Test that every other item's row is shaded, and the last one is marked, in each style.
     *
     * @return void
     */
    public function testShadesEveryOtherItemRow(): void
    {
        $html = $this->html();

        preg_match_all('#<tbody>.*?</tbody>#s', $html, $body);
        preg_match_all('#<tr class="([^"]+)">#', $body[0][0], $rows);
        $this->assertSame(['odd', 'even', 'odd last'], $rows[1]);
    }

    /**
     * Test that the PDF's root has the class of the style, by which its CSS applies.
     *
     * @param InvoiceStyle $style
     * @param string $class
     *
     * @return void
     */
    #[DataProvider('stylesWithTheirRootClass')]
    public function testGivesTheRootTheClassOfTheStyle(InvoiceStyle $style, string $class): void
    {
        $html = $this->html(['presentation' => ['style' => $style->value]]);

        $this->assertStringContainsString("<div class=\"$class\">", $html);
    }

    /**
     * Test that a style, which the catalogue no longer has, renders as `outlined`, and is logged.
     *
     * @return void
     */
    public function testRendersAnUnknownStyleAsOutlinedAndLogsIt(): void
    {
        Log::shouldReceive('warning')->once()->withArgs(fn (string $message, array $context) => $context === ['style' => 'retro']);

        $html = $this->html(['presentation' => ['style' => 'retro']]);

        $this->assertStringContainsString('<div class="inv s-outlined boxed">', $html);
    }

    /**
     * Test that the payment note prints under the payment details, and no other notes without the other note.
     *
     * @return void
     */
    public function testPrintsThePaymentNoteUnderThePaymentDetails(): void
    {
        $html = $this->html(['presentation' => ['payment_note' => 'Quote the invoice number.', 'other_note' => null]]);

        $details = substr($html, (int) strpos($html, '<td class="details'), (int) strpos($html, '<td class="purpose act"') - (int) strpos($html, '<td class="details'));
        $this->assertStringContainsString('<div class="lbl">Payment note</div>', $details);
        $this->assertStringContainsString('Quote the invoice number.', $details);
        $this->assertStringNotContainsString('Other notes', $html);
    }

    /**
     * Test that the other note prints on the right of the card, under the act of acceptance, and no payment note
     * without one.
     *
     * @return void
     */
    public function testPrintsTheOtherNoteUnderTheActOfAcceptance(): void
    {
        $html = $this->html(['presentation' => ['other_note' => 'Some small note for Bring IT LLC.']]);

        $right = substr($html, (int) strpos($html, '<td class="purpose act"'));
        $this->assertMatchesRegularExpression('#Act of acceptance.*<div class="card-section">\s*<div class="lbl">Other notes</div>#s', $right);
        $this->assertStringContainsString('Some small note for Bring IT LLC.', $right);
        $this->assertStringNotContainsString('Payment note', $html);
    }

    /**
     * Test that both notes print at once, each in its place.
     *
     * @return void
     */
    public function testPrintsBothNotesEachInItsPlace(): void
    {
        $html = $this->html(['presentation' => ['payment_note' => 'Quote the invoice number.', 'other_note' => 'Thank you.']]);

        $start = (int) strpos($html, '<td class="details');
        $details = substr($html, $start, (int) strpos($html, '<td class="purpose act"') - $start);
        $right = substr($html, (int) strpos($html, '<td class="purpose act"'));
        $this->assertStringContainsString('Quote the invoice number.', $details);
        $this->assertStringContainsString('Thank you.', $right);
        $this->assertStringNotContainsString('Thank you.', $details);
    }

    /**
     * Test that the other note alone on the right of the card isn't set apart by a rule, when the act note is off.
     *
     * @return void
     */
    public function testPrintsTheOtherNoteAloneOnTheRightWhenTheActNoteIsOff(): void
    {
        $html = $this->html(['presentation' => ['act_note' => null]]);

        $this->assertStringNotContainsString('Act of acceptance', $html);
        $this->assertStringNotContainsString('<div class="card-section">', $html);
        $this->assertStringContainsString('<div class="lbl">Other notes</div>', $html);
    }

    /**
     * Test that the payment details take the card's width, when it has neither the act of acceptance nor the other
     * note.
     *
     * @return void
     */
    public function testGivesThePaymentDetailsTheCardsWidthWithoutItsRightSide(): void
    {
        $html = $this->html(['presentation' => ['act_note' => null, 'other_note' => null]]);

        $this->assertStringContainsString('<td class="details full" style="width: 100%">', $html);
        $this->assertStringNotContainsString('<td class="purpose act"', $html);
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
     * Test that the monogram in the title of Andriichello is the first letters of the second and first words of the
     * ФОП's name, or the first two letters of one word.
     *
     * @param string $name
     * @param string $monogram
     *
     * @return void
     */
    #[DataProvider('namesWithTheirMonograms')]
    public function testDerivesTheMonogramFromTheName(string $name, string $monogram): void
    {
        $html = $this->html(['profile' => ['name' => $name], 'presentation' => ['style' => 'andriichello']]);

        $this->assertStringContainsString("<div class=\"doc-title\"><span class=\"mark\">$monogram</span>Invoice</div>", $html);
    }

    /**
     * Test that a Ukrainian invoice has Ukrainian labels, units and dates, the months in words.
     *
     * @return void
     */
    public function testPrintsTheLabelsUnitsAndDatesInUkrainian(): void
    {
        $html = $this->html(['locale' => 'uk']);

        $this->assertStringContainsString('<div class="label role">фізична особа-підприємець</div>', $html);
        $this->assertStringContainsString('<div class="label">Платник</div>', $html);
        $this->assertStringContainsString('<div class="lbl">Платіжні реквізити</div>', $html);
        $this->assertStringContainsString('<div class="lbl">Акт приймання-передачі</div>', $html);
        $this->assertStringContainsString('<span class="nw">1 жовтня 2026 –</span><br><span class="nw">31 жовтня 2026</span>', $html);
        $this->assertStringContainsString('<span class="party-name">Service Agreement № 109-23</span>', $html);
        $this->assertStringContainsString('<span class="ci">РНОКПП: 1234500592</span>', $html);
        $this->assertStringContainsString('<td class="r mono">20.00 год</td>', $html);
        $this->assertStringContainsString('<td class="k">Призначення</td>', $html);
    }

    /**
     * Test that a draft's preview is marked as a draft in the invoice's language, and the invoice itself isn't.
     *
     * @param string $locale
     * @param string $mark
     *
     * @return void
     */
    #[DataProvider('languagesWithTheDraftMark')]
    public function testMarksADraftsPreviewInTheInvoicesLanguage(string $locale, string $mark): void
    {
        $invoice = new Invoice;
        $invoice->forceFill(['snapshot' => array_replace_recursive($this->snapshot(), ['locale' => $locale])]);
        $renderer = app(InvoicePdfRenderer::class);

        $this->assertStringContainsString("<div class=\"draft-mark\">$mark</div>", $renderer->html($invoice, draft: true));
        $this->assertStringNotContainsString('class="draft-mark"', $renderer->html($invoice));
    }

    /**
     * Test that the client's address prints as typed, line by line, without its blank lines or the country of its own.
     *
     * @return void
     */
    public function testPrintsTheClientsAddressLineByLineAsTyped(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['client']['address'] = ['text' => "Unit 5, Harbour Way\n\n  Dublin D02 X285\nIreland"];
        $snapshot['client']['country'] = 'IE';

        $html = $this->html(replace: $snapshot);

        $this->assertMatchesRegularExpression(
            '#<div class="party-details">Unit 5, Harbour Way</div>\s*<div class="party-details">Dublin D02 X285</div>\s*<div class="party-details">Ireland</div>#',
            $html,
        );
        $this->assertStringNotContainsString('<div class="party-details">IE</div>', $html);
    }

    /**
     * Test that a snapshot of before, whose client address is its parts, prints them as then: the lines, the city with
     * the postal code, and the country.
     *
     * @return void
     */
    public function testPrintsTheClientsAddressPartsOfAnOlderSnapshot(): void
    {
        $html = $this->html();

        $this->assertMatchesRegularExpression(
            '#<div class="party-details">500 Market St, Suite 200</div>\s*<div class="party-details">New Jersey 25902</div>\s*<div class="party-details">US</div>#',
            $html,
        );
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
     * Test that the PDF embeds the fonts of its style: Plex Mono in Andriichello, Unbounded in Free-form, and IBM Plex
     * Mono SemiBold, the face of the invoice's number, in every one.
     *
     * @param InvoiceStyle $style
     * @param string $font
     *
     * @return void
     */
    #[DataProvider('stylesWithTheirFonts')]
    public function testEmbedsTheFontsOfTheStyle(InvoiceStyle $style, string $font): void
    {
        $invoice = new Invoice;
        $invoice->forceFill(['snapshot' => array_replace_recursive($this->snapshot(), ['presentation' => ['style' => $style->value]])]);

        $pdf = app(InvoicePdfRenderer::class)->render($invoice);

        $this->assertMatchesRegularExpression('#/BaseFont /[A-Z]+\+' . preg_quote($font, '#') . '#', $pdf);
        $this->assertMatchesRegularExpression('#/BaseFont /[A-Z]+\+IBMPlexMono-SmBld#', $pdf);
    }

    /**
     * Render the HTML of template v3 for the snapshot with the changes, or for another snapshot.
     *
     * @param array $changes
     * @param array|null $replace
     *
     * @return string
     */
    private function html(array $changes = [], ?array $replace = null): string
    {
        $invoice = new Invoice;
        $invoice->forceFill(['snapshot' => $replace ?? array_replace_recursive($this->snapshot(), $changes)]);

        return app(InvoicePdfRenderer::class)->html($invoice);
    }

    /**
     * A snapshot of version 3 of the invoice BRIT-2026-10-31-2: three items, 5213.00 USD, paid to Payoneer, in
     * Outlined, with the act of acceptance and the other note.
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
            'schema_version' => 3,
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
                'style' => 'outlined',
                'show_contract' => true,
                'payment_purpose' => 'Payment for IT services by the invoice No. BRIT-2026-10-31-2 from Oct 31, 2026',
                'act_note' => 'Payment of this invoice confirms the successful delivery of services and serves as the Act of Acceptance.',
                'payment_note' => null,
                'other_note' => 'Some small note for Bring IT LLC.',
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
        $snapshot['footer_note'] = 'Some small note for Bring IT LLC.';

        return $snapshot;
    }
}
