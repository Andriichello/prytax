<?php

namespace PryTax\Http\Requests\Traits;

use Illuminate\Validation\Rule;
use PryTax\Enums\InvoiceLocale;
use PryTax\Services\Invoices\PaymentPurposeRenderer;

/**
 * Trait InvoiceSettingRules.
 *
 * Rules of the invoice settings of a client and of an invoice (design.md §4.1), each of which is null to take the
 * next level's. "Act note off" is `act_note_enabled = false`, as an empty text is null and takes the next level's.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Requests\Traits
 */
trait InvoiceSettingRules
{
    /**
     * Get the rules of the settings.
     *
     * @param string $localeField The field of the language: `invoice_locale` of a client, `locale` of an invoice.
     *
     * @return array
     */
    protected function invoiceSettingRules(string $localeField): array
    {
        return [
            $localeField => ['sometimes', 'nullable', Rule::enum(InvoiceLocale::class)],
            'payment_purpose_template' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
                app(PaymentPurposeRenderer::class)->templateRule(),
            ],
            'act_note_enabled' => ['sometimes', 'nullable', 'boolean'],
            'act_note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'show_contract' => ['sometimes', 'nullable', 'boolean'],
        ];
    }
}
