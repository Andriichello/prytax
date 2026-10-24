<?php

namespace PryTax\Services\Invoices;

use PryTax\Enums\InvoiceNoteKind;
use PryTax\Enums\InvoiceStyle;

/**
 * Class InvoiceDocument.
 *
 * What the invoice's PDF (template v3) prints, built by InvoicePdfRenderer from a snapshot of version 2 or 3: the
 * texts formatted in the document's language, the classes of the rows and cells, which dompdf can't select by
 * position, the widths of the columns measured, and the style's structure (its layout: the head in a band, the content
 * wrapped over the decoration, its rules, its boxed strip, the monogram, the classes of the decoration's elements and
 * the data URI of its SVG layer), so the template holds no logic.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Services\Invoices
 */
readonly class InvoiceDocument
{
    /**
     * InvoiceDocument constructor.
     *
     * @param InvoiceStyle $style
     * @param string $locale The document's language: `en` or `uk`.
     * @param array{name: string, monogram: string, addressLines: array<int, string>, contacts: array<int, string>} $profile
     * @param string $number
     * @param string $issuedAt
     * @param string|null $dueAt
     * @param string $periodStart
     * @param string $periodEnd
     * @param array{name: string, addressLines: array<int, string>, vatNumber: string|null, taxId: string|null} $client
     * @param bool $showContract
     * @param string|null $contractTitle Such as `Service Agreement No. 109-23`.
     * @param string|null $contractDate
     * @param string $currency
     * @param array<int, array{position: string, codeLine: string, description: string, qty: string, price: string, amount: string, rowClass: string}> $items
     * @param array{no: float, qty: float, price: float, amount: float} $itemsColWidths Content widths of the columns in pt; the description takes the rest.
     * @param array<int, array{label: string, value: string}> $subtotals The subtotal and the adjustment, when there is one.
     * @param string $total Such as `5213.00 USD`.
     * @param array<int, array{label: string, value: string, mono: bool}> $paymentDetails
     * @param float $kvKeyWidth Content width of the names of the payment details (and of the purpose) in pt.
     * @param string $paymentPurpose
     * @param string|null $actNote
     * @param string|null $note
     * @param InvoiceNoteKind $noteKind Where the note prints: under the payment details, or on the right.
     * @param array{banded: bool, wrapped: bool, ruleUnderHead: bool, ruleHead: bool, ruleDouble: bool, boxed: bool, mark: bool, frame: array<int, string>, layer: string|null} $layout The style's structure.
     * @param string|null $signature A data URI of the signature image.
     */
    public function __construct(
        public InvoiceStyle $style,
        public string $locale,
        public array $profile,
        public string $number,
        public string $issuedAt,
        public ?string $dueAt,
        public string $periodStart,
        public string $periodEnd,
        public array $client,
        public bool $showContract,
        public ?string $contractTitle,
        public ?string $contractDate,
        public string $currency,
        public array $items,
        public array $itemsColWidths,
        public array $subtotals,
        public string $total,
        public array $paymentDetails,
        public float $kvKeyWidth,
        public string $paymentPurpose,
        public ?string $actNote,
        public ?string $note,
        public InvoiceNoteKind $noteKind,
        public array $layout,
        public ?string $signature
    ) {}

    /**
     * Get the note, which prints under the payment details, or null.
     *
     * @return string|null
     */
    public function paymentNote(): ?string
    {
        return $this->noteKind === InvoiceNoteKind::Payment ? $this->note : null;
    }

    /**
     * Get the note, which prints on the right of the payment card, under the act, or null.
     *
     * @return string|null
     */
    public function otherNote(): ?string
    {
        return $this->noteKind === InvoiceNoteKind::Other ? $this->note : null;
    }

    /**
     * Determine if the payment card has its right side: the act of acceptance or another note.
     *
     * @return bool
     */
    public function hasCardRight(): bool
    {
        return $this->actNote !== null || $this->otherNote() !== null;
    }
}
