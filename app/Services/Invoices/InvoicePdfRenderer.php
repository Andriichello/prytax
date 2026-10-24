<?php

namespace PryTax\Services\Invoices;

use Barryvdh\DomPDF\Facade\Pdf;
use Core\Models\File;
use Dompdf\FontMetrics;
use PryTax\Helpers\DocumentDate;
use PryTax\Helpers\MoneyFormatter;
use PryTax\Models\Invoice;

/**
 * Class InvoicePdfRenderer.
 *
 * Renders an invoice's PDF from its snapshot only, never from live relations, in the money format, which the snapshot
 * keeps (design.md §4, §10), with dompdf and its bundled DejaVu Sans for Cyrillic. Each generated file records its
 * template, so old invoices keep rendering after the template changes: a snapshot of version 1 renders with template
 * v1 as it always did; one of version 2 with v2 (invoice.md), in the snapshot's language, whose texts and column
 * widths InvoiceDocument carries. The language is passed to every translation, never set on the application.
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
    public const string TEMPLATE_VERSION = 'v2';

    /**
     * Views of the templates by their versions.
     *
     * @var array<string, string>
     */
    public const array TEMPLATES = [
        'v1' => 'pdf.invoice.v1',
        'v2' => 'pdf.invoice.v2',
    ];

    /**
     * Templates, which read a snapshot of version 2.
     *
     * @var array<int, string>
     */
    public const array SCHEMA_2_TEMPLATES = ['v2'];

    /**
     * Ratio of a font's height, by which dompdf sizes a line, in template v2: one, at which a line of DejaVu (ascender
     * 928, descender 236 of 1000) is as high as its `line-height`, as in browsers. dompdf's default (1.1) makes it 1.28
     * times higher; template v1 keeps it.
     *
     * @var float
     */
    public const float FONT_HEIGHT_RATIO = 1000 / 1164;

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

        $pdf = Pdf::loadHTML($this->html($invoice, $template))
            ->setPaper('a4')
            // Embeds only the glyphs in use, rather than whole fonts (megabytes per PDF)
            ->setOption('isFontSubsettingEnabled', true);

        if ($template !== 'v1') {
            $pdf->setOption('fontHeightRatio', self::FONT_HEIGHT_RATIO);
        }

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

        return view(self::TEMPLATES[$template], ['doc' => $this->document($snapshot, $signature)])->render();
    }

    /**
     * Get the template of the invoice's PDF: v1 for a snapshot of version 1; for a newer one, the template of the
     * generated file, which a render replaces, when it reads the snapshot, or else the newest one.
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

        return in_array($stored, self::SCHEMA_2_TEMPLATES, true) ? $stored : self::TEMPLATE_VERSION;
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
     * Build what template v2 prints of a snapshot of version 2.
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

        $items = array_map(fn (array $item) => [
            'position' => (string) $item['position'],
            'codeLine' => implode(' · ', array_filter([$item['project_code'] ?? null, $item['charge_type'] ?? null])),
            'description' => (string) $item['description'],
            'qty' => trim($money->amount($item['quantity']) . ' ' . $label('unit.' . $item['unit'])),
            'price' => $money->amount($item['unit_price']),
            'amount' => $money->amount($item['amount']),
        ], $snapshot['items'] ?? []);

        $adjustments = ($totals['adjustment_amount'] ?? '0.00') === '0.00' ? [] : [
            ['label' => $label('subtotal'), 'value' => $money->amount($totals['subtotal'] ?? 0)],
            ['label' => ($totals['adjustment_label'] ?? null) ?: $label('adjustment'), 'value' => $money->amount($totals['adjustment_amount'])],
        ];

        $paymentDetails = $this->paymentDetails($account, $currency, $label);

        return new InvoiceDocument(
            locale: $locale,
            profile: [
                'name' => (string) ($profile['name'] ?? ''),
                'taxIdLabel' => $label(($profile['tax_id_kind'] ?? null) === 'passport' ? 'passport' : 'tax_id'),
                'taxId' => (string) ($profile['tax_id'] ?? ''),
                'addressLines' => $this->lines($profile['address'] ?? null),
                'email' => ($profile['email'] ?? null) ?: null,
                'phone' => ($profile['phone'] ?? null) ?: null,
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
            itemsColWidths: $this->itemsColWidths($items, $currency, $label),
            adjustments: $adjustments,
            total: $money->money($totals['total'] ?? 0, $currency),
            paymentDetails: $paymentDetails,
            kvKeyWidth: $this->ceil(max(array_map(fn (array $row) => $this->textWidth($row['label'], 'DejaVu Sans', 'normal', 7.5), $paymentDetails) ?: [0])),
            paymentPurpose: (string) ($presentation['payment_purpose'] ?? ''),
            actNote: ($presentation['act_note'] ?? null) ?: null,
            footerNote: ($snapshot['footer_note'] ?? null) ?: null,
            signature: $signature,
        );
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
     * Get the content widths of the items' columns in pt, which fit their contents: the number column its numbers,
     * and each of quantity, price and amount its longest value or the longest word of its header (a header may wrap).
     * The cells' padding adds the space before a value; the description takes the rest.
     *
     * @param array<int, array{position: string, qty: string, price: string, amount: string}> $items
     * @param string $currency
     * @param callable(string, array=): string $label
     *
     * @return array{no: float, qty: float, price: float, amount: float}
     */
    public function itemsColWidths(array $items, string $currency, callable $label): array
    {
        $value = fn (string $text) => $this->textWidth($text, 'DejaVu Sans Mono', 'normal', 7.5);
        $header = fn (string $text) => max(array_map(
            fn (string $word) => $this->textWidth(mb_strtoupper($word), 'DejaVu Sans', 'bold', 6, 0.04 * 6),
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
     * Measure the text in the font of dompdf, in pt.
     *
     * @param string $text
     * @param string $family
     * @param string $weight `normal` or `bold`.
     * @param float $size
     * @param float $letterSpacing
     *
     * @return float
     */
    protected function textWidth(string $text, string $family, string $weight, float $size, float $letterSpacing = 0.0): float
    {
        $this->metrics ??= app('dompdf')->getFontMetrics();

        return $this->metrics->getTextWidth($text, $this->metrics->getFont($family, $weight), $size, 0.0, $letterSpacing);
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
