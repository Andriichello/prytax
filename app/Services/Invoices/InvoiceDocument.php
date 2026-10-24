<?php

namespace PryTax\Services\Invoices;

/**
 * Class InvoiceDocument.
 *
 * What the invoice's PDF (template v2) prints, built by InvoicePdfRenderer from a snapshot of version 2: the texts
 * formatted in the document's language and the widths of the columns measured, so the template holds no logic.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Services\Invoices
 */
readonly class InvoiceDocument
{
    /**
     * InvoiceDocument constructor.
     *
     * @param string $locale The document's language: `en` or `uk`.
     * @param array{name: string, taxIdLabel: string, taxId: string, addressLines: array<int, string>, email: string|null, phone: string|null} $profile
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
     * @param array<int, array{position: string, codeLine: string, description: string, qty: string, price: string, amount: string}> $items
     * @param array{no: float, qty: float, price: float, amount: float} $itemsColWidths Content widths of the columns in pt; the description takes the rest.
     * @param array<int, array{label: string, value: string}> $adjustments The subtotal and the adjustment, when there is one.
     * @param string $total Such as `5213.00 USD`.
     * @param array<int, array{label: string, value: string, mono: bool}> $paymentDetails
     * @param float $kvKeyWidth Content width of the names of the payment details in pt.
     * @param string $paymentPurpose
     * @param string|null $actNote
     * @param string|null $footerNote
     * @param string|null $signature A data URI of the signature image.
     */
    public function __construct(
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
        public array $adjustments,
        public string $total,
        public array $paymentDetails,
        public float $kvKeyWidth,
        public string $paymentPurpose,
        public ?string $actNote,
        public ?string $footerNote,
        public ?string $signature
    ) {}
}
