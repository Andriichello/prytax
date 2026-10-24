<?php

namespace PryTax\Services\Invoices;

use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdfWrapper;
use Core\Models\File;
use Dompdf\Canvas;
use Dompdf\FontMetrics;
use FontLib\Font;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PryTax\Enums\InvoiceNoteKind;
use PryTax\Enums\InvoiceStyle;
use PryTax\Helpers\DocumentDate;
use PryTax\Helpers\MoneyFormatter;
use PryTax\Models\Invoice;

/**
 * Class InvoicePdfRenderer.
 *
 * Renders an invoice's PDF from its snapshot only, never from live relations, in the money format, which the snapshot
 * keeps (design.md §4, §10), with dompdf and its bundled DejaVu fonts for Cyrillic. Each generated file records its
 * template, so old invoices keep rendering after the template changes: a snapshot of version 1 renders with template
 * v1 as it always did; a newer one with v3 (INVOICE_STYLES.md), in the snapshot's language and style, whose texts,
 * classes, column widths and decoration InvoiceDocument carries. Version 2 snapshots never left development; they
 * render with v3 as `outlined`, their footer note as another note. The language is passed to every translation, never
 * set on the application. Every page is numbered at its bottom right, once dompdf knows how many there are.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Services\Invoices
 */
class InvoicePdfRenderer
{
    /**
     * Template of the new renders, which is recorded with each generated file.
     *
     * @var string
     */
    public const string TEMPLATE_VERSION = 'v3';

    /**
     * Views of the templates by their versions.
     *
     * @var array<string, string>
     */
    public const array TEMPLATES = [
        'v1' => 'pdf.invoice.v1',
        'v3' => 'pdf.invoice.v3',
    ];

    /**
     * Ratio of a font's height, by which dompdf sizes a line, from template v3 on: one, at which a line of DejaVu
     * (ascender 928, descender 236 of 1000) is as high as its `line-height`, as in browsers. dompdf's default (1.1)
     * makes it 1.28 times higher; template v1 keeps it.
     *
     * @var float
     */
    public const float FONT_HEIGHT_RATIO = 1000 / 1164;

    /**
     * Fonts beyond dompdf's DejaVu, by their family and weight, and their files in `resources/fonts`, which
     * installedMetrics() installs. dompdf looks a font up by its exact weight (600 is neither normal nor bold), so
     * each weight, which the styles use, has one: Plex Mono's 600 and bold are its SemiBold; Unbounded has no Regular,
     * so its normal is the SemiBold too.
     *
     * @var array<string, array<string, string>>
     */
    public const array FONTS = [
        'PlexMonoSemi' => ['normal' => 'IBMPlexMono-SemiBold.ttf'],
        'Plex Mono' => ['normal' => 'IBMPlexMono-Regular.ttf', '600' => 'IBMPlexMono-SemiBold.ttf', 'bold' => 'IBMPlexMono-SemiBold.ttf'],
        'Unbounded' => ['normal' => 'Unbounded-SemiBold.ttf', '600' => 'Unbounded-SemiBold.ttf', 'bold' => 'Unbounded-Bold.ttf'],
    ];

    /**
     * Font metrics of dompdf, which measure the columns' texts.
     *
     * @var FontMetrics|null
     */
    protected ?FontMetrics $metrics = null;

    /**
     * Render the PDF of the frozen invoice with the template, or else the one, which its snapshot and file take.
     *
     * @param Invoice $invoice
     * @param string|null $template
     *
     * @return string
     */
    public function render(Invoice $invoice, ?string $template = null): string
    {
        $template ??= $this->templateFor($invoice);

        if ($template === 'v1') {
            return $this->pdf($this->html($invoice, 'v1'))->output();
        }

        $document = $this->document($invoice->snapshot ?? [], $this->signature($invoice->snapshot ?? []));
        $pdf = $this->pdf($this->documentHtml($document))->setOption('fontHeightRatio', self::FONT_HEIGHT_RATIO);

        $pdf->render();
        $this->numberPages($pdf->getDomPDF()->getCanvas(), $document);

        return $pdf->output();
    }

    /**
     * Render the HTML of the frozen invoice's PDF with the template, or else the one, which its snapshot and file
     * take.
     *
     * @param Invoice $invoice
     * @param string|null $template
     *
     * @return string
     */
    public function html(Invoice $invoice, ?string $template = null): string
    {
        $snapshot = $invoice->snapshot ?? [];
        $template ??= $this->templateFor($invoice);
        $signature = $this->signature($snapshot);

        if ($template === 'v1') {
            return view(self::TEMPLATES['v1'], [
                'snapshot' => $snapshot,
                'money' => MoneyFormatter::of($snapshot['money_format'] ?? null),
                'signature' => $signature,
            ])->render();
        }

        return $this->documentHtml($this->document($snapshot, $signature));
    }

    /**
     * Get the template of the invoice's PDF: v1 for a snapshot of version 1; for a newer one, the template of the
     * generated file, which a render replaces, when it is a newer one still, or else the newest one.
     *
     * @param Invoice $invoice
     *
     * @return string
     */
    public function templateFor(Invoice $invoice): string
    {
        if ((int) data_get($invoice->snapshot, 'schema_version', 1) < 2) {
            return 'v1';
        }

        $file = $invoice->primaryFile;
        $stored = $file?->collection === Invoice::GENERATED_COLLECTION ? data_get($file->metadata, 'template_version') : null;

        return $stored !== 'v1' && array_key_exists((string) $stored, self::TEMPLATES) ? $stored : self::TEMPLATE_VERSION;
    }

    /**
     * Render the PDF and attach it to the invoice as its generated, primary document.
     *
     * @param Invoice $invoice
     *
     * @return File
     */
    public function attachGenerated(Invoice $invoice): File
    {
        $template = $this->templateFor($invoice);

        $file = $invoice->attachContents(
            $this->render($invoice, $template),
            ($invoice->number ?? 'invoice') . '.pdf',
            Invoice::GENERATED_COLLECTION,
            ['template_version' => $template, 'schema_version' => data_get($invoice->snapshot, 'schema_version')],
        );

        $invoice->forceFill(['primary_file_id' => $file->getKey()])->save();

        return $file;
    }

    /**
     * Build what template v3 prints of a snapshot of version 2 or 3.
     *
     * @param array $snapshot
     * @param string|null $signature
     *
     * @return InvoiceDocument
     */
    public function document(array $snapshot, ?string $signature = null): InvoiceDocument
    {
        $locale = $snapshot['locale'] ?? 'en';
        $label = fn (string $key, array $replace = []) => trans("invoice.labels.$key", $replace, $locale);
        $date = fn (?string $value) => DocumentDate::format($value, $locale);
        $money = MoneyFormatter::of($snapshot['money_format'] ?? null);

        $invoice = $snapshot['invoice'] ?? [];
        $profile = $snapshot['profile'] ?? [];
        $client = $snapshot['client'] ?? [];
        $contract = $snapshot['contract'] ?? null;
        $account = $snapshot['account'] ?? [];
        $totals = $snapshot['totals'] ?? [];
        $presentation = $snapshot['presentation'] ?? [];
        $currency = $invoice['currency'] ?? '';
        $address = $client['address'] ?? [];
        $style = $this->style($presentation['style'] ?? null);

        $count = count($snapshot['items'] ?? []);
        $items = array_map(fn (array $item, int $index) => [
            'position' => (string) $item['position'],
            'codeLine' => implode(' · ', array_filter([$item['project_code'] ?? null, $item['charge_type'] ?? null])),
            'description' => (string) $item['description'],
            'qty' => trim($money->amount($item['quantity']) . ' ' . $label('unit.' . $item['unit'])),
            'price' => $money->amount($item['unit_price']),
            'amount' => $money->amount($item['amount']),
            'rowClass' => ($index % 2 === 0 ? 'odd' : 'even') . ($index === $count - 1 ? ' last' : ''),
        ], $snapshot['items'] ?? [], array_keys($snapshot['items'] ?? []));

        $subtotals = ($totals['adjustment_amount'] ?? '0.00') === '0.00' ? [] : [
            ['label' => $label('subtotal'), 'value' => $money->amount($totals['subtotal'] ?? 0)],
            ['label' => ($totals['adjustment_label'] ?? null) ?: $label('adjustment'), 'value' => $money->amount($totals['adjustment_amount'])],
        ];

        $paymentDetails = $this->paymentDetails($account, $currency, $label);
        $keys = [...array_column($paymentDetails, 'label'), $label('purpose_short')];

        // A schema 2 snapshot has a footer note instead of the note and its kind
        $note = array_key_exists('note', $presentation) ? $presentation['note'] : ($snapshot['footer_note'] ?? null);

        return new InvoiceDocument(
            style: $style,
            locale: $locale,
            profile: [
                'name' => (string) ($profile['name'] ?? ''),
                'monogram' => $this->monogram((string) ($profile['name'] ?? '')),
                'addressLines' => $this->lines($profile['address'] ?? null),
                'contacts' => array_values(array_filter([
                    filled($profile['tax_id'] ?? null)
                        ? $label(($profile['tax_id_kind'] ?? null) === 'passport' ? 'passport' : 'tax_id') . ': ' . $profile['tax_id']
                        : null,
                    ($profile['email'] ?? null) ?: null,
                    ($profile['phone'] ?? null) ?: null,
                ])),
            ],
            number: (string) ($invoice['number'] ?? ''),
            issuedAt: $date($invoice['issued_at'] ?? null),
            dueAt: empty($invoice['due_at']) ? null : $date($invoice['due_at']),
            periodStart: $date($invoice['period_start'] ?? null),
            periodEnd: $date($invoice['period_end'] ?? null),
            client: [
                'name' => (string) ($client['name'] ?? ''),
                'addressLines' => array_values(array_filter([
                    ...($address['lines'] ?? []),
                    trim(($address['city'] ?? '') . ' ' . ($address['zip'] ?? '')),
                    $client['country'] ?? null,
                ], fn (?string $line) => filled($line))),
                'vatNumber' => ($client['vat_number'] ?? null) ?: null,
                'taxId' => ($client['tax_id'] ?? null) ?: null,
            ],
            showContract: ($presentation['show_contract'] ?? true) && $contract !== null,
            contractTitle: $contract === null ? null
                : trim($contract['name'] . (filled($contract['number'] ?? null) ? ' ' . $label('number') . ' ' . $contract['number'] : '')),
            contractDate: $contract === null ? null : $date($contract['started_at'] ?? null),
            currency: $currency,
            items: $items,
            itemsColWidths: $this->itemsColWidths($items, $currency, $label, $style),
            subtotals: $subtotals,
            total: $money->money($totals['total'] ?? 0, $currency),
            paymentDetails: $paymentDetails,
            kvKeyWidth: $this->ceil(max(array_map(fn (string $key) => $this->textWidth($key, 'DejaVu Sans', 'normal', 7.5), $keys))),
            paymentPurpose: (string) ($presentation['payment_purpose'] ?? ''),
            actNote: ($presentation['act_note'] ?? null) ?: null,
            note: filled($note) ? (string) $note : null,
            noteKind: InvoiceNoteKind::tryFrom((string) ($presentation['note_kind'] ?? '')) ?? InvoiceNoteKind::Other,
            layout: $this->layout($style),
            signature: $signature,
        );
    }

    /**
     * Get the content widths of the items' columns in pt, which fit their contents: the number column its numbers,
     * and each of quantity, price and amount its longest value or the longest word of its header (a header may wrap),
     * in the style's font of the headers. The cells' padding adds the space before a value; the description takes
     * the rest.
     *
     * @param array<int, array{position: string, qty: string, price: string, amount: string}> $items
     * @param string $currency
     * @param callable(string, array=): string $label
     * @param InvoiceStyle $style
     *
     * @return array{no: float, qty: float, price: float, amount: float}
     */
    public function itemsColWidths(array $items, string $currency, callable $label, InvoiceStyle $style = InvoiceStyle::Outlined): array
    {
        [$family, $weight, $size, $spacing] = $this->headerFont($style);

        $value = fn (string $text) => $this->textWidth($text, 'DejaVu Sans Mono', 'normal', 7.5);
        $header = fn (string $text) => max(array_map(
            fn (string $word) => $this->textWidth(mb_strtoupper($word), $family, $weight, $size, $spacing * $size),
            explode(' ', $text),
        ));
        $column = fn (string $key, string $heading) => $this->ceil(max(
            $header($heading),
            ...array_map(fn (array $item) => $value($item[$key]), $items ?: [[$key => '']]),
        ));

        return [
            'no' => $column('position', $label('col_no')),
            'qty' => $column('qty', $label('col_qty')),
            'price' => $column('price', $label('col_price', ['cur' => $currency])),
            'amount' => $column('amount', $label('col_amount', ['cur' => $currency])),
        ];
    }

    /**
     * Get the monogram of a name: the first letters of its second and first words (`Prykhodko Andrii` → `AP`), or the
     * first two letters of a name of one word, upper-cased.
     *
     * @param string $name
     *
     * @return string
     */
    public function monogram(string $name): string
    {
        $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $letters = count($words) > 1
            ? mb_substr($words[1], 0, 1) . mb_substr($words[0], 0, 1)
            : mb_substr($words[0] ?? '', 0, 2);

        return mb_strtoupper($letters);
    }

    /**
     * Get the style of a snapshot's presentation: `outlined` when it has none (version 2), or one, which the catalogue
     * no longer has, which is logged.
     *
     * @param string|null $key
     *
     * @return InvoiceStyle
     */
    protected function style(?string $key): InvoiceStyle
    {
        $style = InvoiceStyle::tryFrom((string) $key);

        if ($style === null && $key !== null) {
            Log::warning('An invoice is rendered as outlined, since its style is unknown.', ['style' => $key]);
        }

        return $style ?? InvoiceStyle::Outlined;
    }

    /**
     * Get the structure of the style: whether its head is in a band, its content wrapped over its decoration, which
     * rules it has, whether its strip is boxed, the monogram in its title, the elements of its decoration and the
     * data URI of its SVG layer.
     *
     * @param InvoiceStyle $style
     *
     * @return array{banded: bool, wrapped: bool, ruleUnderHead: bool, ruleHead: bool, ruleDouble: bool, boxed: bool, mark: bool, frame: array<int, string>, layer: string|null}
     */
    protected function layout(InvoiceStyle $style): array
    {
        $plain = [InvoiceStyle::Outlined, InvoiceStyle::Simplified, InvoiceStyle::Colored];
        $layer = resource_path("invoice-decor/{$style->value}.svg");

        return [
            'banded' => in_array($style, [InvoiceStyle::Colored, InvoiceStyle::Andriichello, InvoiceStyle::AndriichelloSchema,
                InvoiceStyle::AndriichelloCarpathians], true),
            'wrapped' => !in_array($style, $plain, true),
            'ruleUnderHead' => !in_array($style, [...$plain, InvoiceStyle::Andriichello], true),
            'ruleHead' => $style === InvoiceStyle::Simplified,
            'ruleDouble' => $style === InvoiceStyle::Outlined,
            'boxed' => $style !== InvoiceStyle::Simplified,
            'mark' => $style === InvoiceStyle::Andriichello,
            'frame' => match ($style) {
                InvoiceStyle::Outlined => ['rule-foot'],
                InvoiceStyle::Colored => ['foot-band'],
                InvoiceStyle::Vivid => ['topbar'],
                InvoiceStyle::Freeform, InvoiceStyle::FreeformMono => ['shape c1', 'shape c2', 'shape c3', 'shape ring', 'shape sq', 'shape dot'],
                default => [],
            },
            'layer' => is_file($layer) ? 'data:image/svg+xml;base64,' . base64_encode((string) file_get_contents($layer)) : null,
        ];
    }

    /**
     * Get the font of the items' headers in the style: its family, weight, size in pt and letter spacing in em.
     *
     * @param InvoiceStyle $style
     *
     * @return array{0: string, 1: string, 2: float, 3: float}
     */
    protected function headerFont(InvoiceStyle $style): array
    {
        return match ($style) {
            InvoiceStyle::Freeform, InvoiceStyle::FreeformMono, InvoiceStyle::FreeformFacets, InvoiceStyle::FreeformBlocks,
            InvoiceStyle::AndriichelloCarpathians => ['Unbounded', '600', 5.6, 0.04],
            InvoiceStyle::Andriichello => ['Plex Mono', 'bold', 6.0, 0.06],
            InvoiceStyle::AndriichelloSchema => ['Plex Mono', 'bold', 6.0, 0.04],
            InvoiceStyle::Monochrome => ['DejaVu Sans', 'bold', 6.0, 0.1],
            default => ['DejaVu Sans', 'bold', 6.0, 0.04],
        };
    }

    /**
     * Get the families, which the style uses beyond DejaVu and the invoice number's own.
     *
     * @param InvoiceStyle $style
     *
     * @return array<int, string>
     */
    protected function families(InvoiceStyle $style): array
    {
        return match ($style) {
            InvoiceStyle::Freeform, InvoiceStyle::FreeformMono, InvoiceStyle::FreeformFacets, InvoiceStyle::FreeformBlocks,
            InvoiceStyle::AndriichelloCarpathians => ['Unbounded'],
            InvoiceStyle::Andriichello, InvoiceStyle::AndriichelloSchema => ['Plex Mono'],
            default => [],
        };
    }

    /**
     * Get the CSS of the style: the shared rules, then its own.
     *
     * @param InvoiceStyle $style
     *
     * @return string
     */
    protected function css(InvoiceStyle $style): string
    {
        return file_get_contents(resource_path('invoice-styles/base.css'))
            . file_get_contents(resource_path("invoice-styles/{$style->value}.css"));
    }

    /**
     * Render the HTML of template v3 for the document.
     *
     * @param InvoiceDocument $document
     *
     * @return string
     */
    protected function documentHtml(InvoiceDocument $document): string
    {
        $this->metrics ??= $this->installedMetrics();

        return view(self::TEMPLATES['v3'], ['doc' => $document, 'css' => $this->css($document->style)])->render();
    }

    /**
     * Make a dompdf document of the HTML on A4, embedding only the glyphs in use, rather than whole fonts (megabytes
     * per PDF).
     *
     * @param string $html
     *
     * @return DomPdfWrapper
     */
    protected function pdf(string $html): DomPdfWrapper
    {
        return Pdf::loadHTML($html)->setPaper('a4')->setOption('isFontSubsettingEnabled', true);
    }

    /**
     * Number every page at its bottom right, as the style's `.pager` rules place it: "Page 1 of 2", the label in the
     * pager's font and the numbers in DejaVu Sans Mono, right-aligned 30pt from the page's edge.
     *
     * @param Canvas $canvas
     * @param InvoiceDocument $document
     *
     * @return void
     */
    protected function numberPages(Canvas $canvas, InvoiceDocument $document): void
    {
        $pager = $this->pager($document->style);
        $label = trans('invoice.labels.page_of', [], $document->locale);
        $color = array_map(fn (string $hex) => hexdec($hex) / 255, str_split(ltrim($pager['color'], '#'), 2));

        $canvas->page_script(function (int $page, int $total, Canvas $canvas, FontMetrics $metrics) use ($pager, $label, $color) {
            $text = $metrics->getFont($pager['family']) ?? $metrics->getFont('DejaVu Sans');
            $mono = $metrics->getFont('DejaVu Sans Mono');
            $parts = preg_split('/(:page|:total)/', $label, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
            $runs = array_map(fn (string $part) => match ($part) {
                ':page' => [(string) $page, $mono],
                ':total' => [(string) $total, $mono],
                default => [$part, $text],
            }, $parts);

            $width = array_sum(array_map(fn (array $run) => $metrics->getTextWidth($run[0], $run[1], 7), $runs));
            $x = $canvas->get_width() - 30 - $width;
            $y = $canvas->get_height() - $pager['bottom'] - 8.5;

            foreach ($runs as [$part, $font]) {
                $canvas->text($x, $y, $part, $font, 7, $color);
                $x += $metrics->getTextWidth($part, $font, 7);
            }
        });
    }

    /**
     * Get where the style's page number goes, and how it looks, from its `.pager` rules: its distance from the
     * page's bottom in pt, its colour and its font.
     *
     * @param InvoiceStyle $style
     *
     * @return array{bottom: float, color: string, family: string}
     */
    protected function pager(InvoiceStyle $style): array
    {
        $pager = ['bottom' => 12.0, 'color' => '#5A5F6B', 'family' => 'DejaVu Sans'];

        preg_match_all('/([^{}]*\.pager)\s*\{([^}]*)}/', $this->css($style), $rules, PREG_SET_ORDER);

        foreach ($rules as [, , $body]) {
            if (preg_match('/bottom:\s*([\d.]+)pt/', $body, $match)) {
                $pager['bottom'] = (float) $match[1];
            }

            if (preg_match('/color:\s*(#[0-9A-Fa-f]{6})/', $body, $match)) {
                $pager['color'] = $match[1];
            }

            if (preg_match("/font-family:\s*'([^']+)'/", $body, $match)) {
                $pager['family'] = $match[1];
            }
        }

        return $pager;
    }

    /**
     * Get the rows of the payment details, in the order of template v1: the account's own fields, its details as they
     * were typed, and the currency.
     *
     * @param array $account
     * @param string $currency
     * @param callable(string): string $label
     *
     * @return array<int, array{label: string, value: string, mono: bool}>
     */
    protected function paymentDetails(array $account, string $currency, callable $label): array
    {
        $rows = [];

        foreach (['holder_name' => ['beneficiary', false], 'bank_name' => ['bank', false], 'iban' => ['iban', true],
            'account_number' => ['account_number', true], 'swift' => ['swift', true]] as $field => [$key, $mono]) {
            if (filled($account[$field] ?? null)) {
                $rows[] = ['label' => $label($key), 'value' => (string) $account[$field], 'mono' => $mono];
            }
        }

        foreach ($account['details'] ?? [] as $detail) {
            $rows[] = ['label' => (string) ($detail['label'] ?? ''), 'value' => (string) ($detail['value'] ?? ''), 'mono' => true];
        }

        $rows[] = ['label' => $label('currency'), 'value' => (string) (($account['currency'] ?? null) ?: $currency), 'mono' => false];

        return $rows;
    }

    /**
     * Measure the text in the font of dompdf, in pt.
     *
     * @param string $text
     * @param string $family
     * @param string $weight `normal`, `600` or `bold`.
     * @param float $size
     * @param float $letterSpacing
     *
     * @return float
     */
    protected function textWidth(string $text, string $family, string $weight, float $size, float $letterSpacing = 0.0): float
    {
        $this->metrics ??= $this->installedMetrics();

        return $this->metrics->getTextWidth($text, $this->metrics->getFont($family, $weight), $size, 0.0, $letterSpacing);
    }

    /**
     * Get dompdf's font metrics with the fonts beyond DejaVu installed in its font directory, which every dompdf
     * document reads, rather than by `@font-face`, so they measure before any PDF has used them, and so their height
     * is DejaVu's: dompdf makes a line `line-height × (ascender − descender) / 1164` high (at FONT_HEIGHT_RATIO),
     * which is the CSS one for DejaVu only, and 1.12 times higher for Plex Mono (1300), 1.07 for Unbounded (1240). An
     * offset in their metrics makes up the difference, which dompdf adds to the font's height. The invoice number's
     * PlexMonoSemi keeps its height: it shares a line with DejaVu, which browsers make as much higher. A font is
     * installed again when its file is newer than the installed one.
     *
     * @return FontMetrics
     */
    protected function installedMetrics(): FontMetrics
    {
        $metrics = app('dompdf')->getFontMetrics();
        $directory = $metrics->getOptions()->getFontDir();

        foreach (self::FONTS as $family => $weights) {
            $entry = $metrics->getFontFamilies()[mb_strtolower($family)] ?? [];
            $installed = $entry;

            foreach ($weights as $weight => $file) {
                $source = resource_path("fonts/$file");
                $path = $directory . '/prytax_' . Str::slug($family, '_') . '_' . $weight;

                if (!is_file("$path.ufm") || filemtime($source) > filemtime("$path.ufm")) {
                    $this->installFont($source, $path, $family !== 'PlexMonoSemi');
                }

                $entry[$weight] = $path;
            }

            if ($entry !== $installed) {
                $metrics->setFontFamily($family, $entry);
            }
        }

        return $metrics;
    }

    /**
     * Copy a font into dompdf's font directory with its metrics, whose height an offset makes DejaVu's, when it is
     * levelled.
     *
     * @param string $source
     * @param string $path The installed font's path without its extension.
     * @param bool $levelled
     *
     * @return void
     */
    protected function installFont(string $source, string $path, bool $levelled): void
    {
        copy($source, "$path.ttf");

        $font = Font::load("$path.ttf");
        $font->parse();
        $font->saveAdobeFontMetrics("$path.ufm");
        $font->close();

        $metrics = (string) file_get_contents("$path.ufm");
        preg_match('/^Ascender (-?\d+)/m', $metrics, $ascender);
        preg_match('/^Descender (-?\d+)/m', $metrics, $descender);
        $offset = 1164 - ((int) ($ascender[1] ?? 928) - (int) ($descender[1] ?? -236));

        if ($levelled) {
            $metrics = preg_replace('/^(Descender -?\d+)$/m', "$1\nFontHeightOffset $offset", $metrics, 1);
        }

        file_put_contents("$path.ufm", $metrics);
        @unlink("$path.ufm.json");
    }

    /**
     * Round the width up to a tenth of a pt.
     *
     * @param float $width
     *
     * @return float
     */
    protected function ceil(float $width): float
    {
        return ceil($width * 10) / 10;
    }

    /**
     * Split a text, which may have several lines, into its lines, which aren't blank.
     *
     * @param string|null $text
     *
     * @return array<int, string>
     */
    protected function lines(?string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/u', (string) $text) ?: []), fn (string $line) => $line !== ''));
    }

    /**
     * Get the signature image of the snapshot's profile as a data URI, or null.
     *
     * @param array $snapshot
     *
     * @return string|null
     */
    protected function signature(array $snapshot): ?string
    {
        $id = data_get($snapshot, 'profile.signature_file_id');
        $file = $id ? File::query()->find($id) : null;
        $contents = $file?->contents();

        return $contents === null ? null : 'data:' . $file->mime_type . ';base64,' . base64_encode($contents);
    }
}
