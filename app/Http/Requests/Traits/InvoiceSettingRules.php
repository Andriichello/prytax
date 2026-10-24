<?php

namespace PryTax\Http\Requests\Traits;

use Illuminate\Validation\Rule;
use PryTax\Enums\InvoiceLocale;
use PryTax\Enums\InvoiceNoteKind;
use PryTax\Enums\InvoiceStyle;
use PryTax\Rules\NoteTextRequired;
use PryTax\Services\Invoices\PaymentPurposeRenderer;

/**
 * Trait InvoiceSettingRules.
 *
 * Rules of the invoice settings of a client and of an invoice (design.md §4.1), each of which is null to take the
 * next level's. "Act note off" is `act_note_enabled = false`, as an empty text is null and takes the next level's. A
 * note, which its level switches on, needs its text there, as the level owns the note whole.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Requests\Traits
 */
trait InvoiceSettingRules
{
    /**
     * Get the rules of the settings.
     *
     * @param string $prefix Prefix of the fields of the language, style and note: `invoice_` of a client (its
     *                       `invoice_locale`, `invoice_style`, `invoice_note…`), none of an invoice.
     *
     * @return array
     */
    protected function invoiceSettingRules(string $prefix = ''): array
    {
        return [
            $prefix . 'locale' => ['sometimes', 'nullable', Rule::enum(InvoiceLocale::class)],
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
            $prefix . 'style' => ['sometimes', 'nullable', Rule::enum(InvoiceStyle::class)],
            $prefix . 'note_enabled' => ['sometimes', 'nullable', 'boolean'],
            $prefix . 'note' => ['sometimes', 'nullable', 'string', 'max:500', new NoteTextRequired($prefix . 'note_enabled')],
            $prefix . 'note_kind' => ['sometimes', 'nullable', Rule::enum(InvoiceNoteKind::class)],
        ];
    }
}
