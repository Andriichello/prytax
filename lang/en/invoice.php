<?php

/*
 * Texts of the invoice PDF in English (template v2 and later; v1 keeps its own): the labels, and the built-in texts of
 * the payment purpose and the act note (design.md §4.1), which a profile, a client or an invoice may replace. The
 * labels are written in normal case: the template upper-cases the title and the section labels.
 */
return [
    'labels' => [
        'title' => 'Invoice',
        'number' => 'No.',
        'issued' => 'Issued',
        'due' => 'Due',
        'entrepreneur' => 'Individual Entrepreneur',
        'tax_id' => 'Tax ID',
        'passport' => 'Passport',
        'bill_to' => 'Bill to',
        'vat' => 'VAT',
        'client_tax_id' => 'Tax ID',
        'period' => 'Period',
        'contract' => 'Contract',
        'contract_dated' => 'dated :date',
        'col_no' => '#',
        'col_desc' => 'Description',
        'col_qty' => 'Qty',
        'col_price' => 'Price, :cur',
        'col_amount' => 'Amount, :cur',
        'subtotal' => 'Subtotal',
        'adjustment' => 'Adjustment',
        'total' => 'Total',
        'unit' => [
            'hour' => 'h',
            'fixed' => 'fixed',
            'item' => 'pcs',
        ],
        'payment_details' => 'Payment details',
        'beneficiary' => 'Beneficiary',
        'bank' => 'Bank',
        'iban' => 'IBAN',
        'account_number' => 'Account number',
        'swift' => 'SWIFT / BIC',
        'currency' => 'Currency',
        'payment_purpose' => 'Payment purpose',
        'act_of_acceptance' => 'Act of acceptance',
    ],

    'defaults' => [
        // "No.", not "№", which is outside the SWIFT and SEPA character sets
        'payment_purpose' => 'Payment for {services} by the invoice No. {invoice.number} from {invoice.date}',
        'services' => 'services',
        'act_note' => 'Payment of this invoice confirms the successful delivery of services and serves as the Act of Acceptance. No additional signatures are required.',
    ],
];
