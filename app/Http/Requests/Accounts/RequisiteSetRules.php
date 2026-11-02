<?php

namespace PryTax\Http\Requests\Accounts;

use Illuminate\Validation\Rule;
use PryTax\Http\Requests\Traits\ScopesToProfile;
use PryTax\Services\Accounts\InvoiceRequisites;

/**
 * Trait RequisiteSetRules.
 *
 * Rules of a set of an account's requisites, which its store and update requests share: its name (in an invoice's
 * parentheses), the requisites, SWIFT and BIC apart, its own details, their order in invoices and which of them print
 * (`InvoiceRequisites`), whether it's the default and whether it's offered.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Requests\Accounts
 */
trait RequisiteSetRules
{
    use ScopesToProfile;

    /**
     * Get the rules of the set's fields.
     *
     * @return array
     */
    protected function requisiteSetRules(): array
    {
        $code = ['sometimes', 'nullable', 'string', 'regex:/^[A-Z0-9]{8}([A-Z0-9]{3})?$/'];

        return [
            'name' => ['sometimes', 'nullable', 'string', 'max:64'],
            'holder_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'bank_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'iban' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Z]{2}[0-9A-Z]{13,32}$/'],
            'account_number' => ['sometimes', 'nullable', 'string', 'max:64'],
            'swift' => $code,
            'bic' => $code,
            'details' => ['sometimes', 'nullable', 'array', 'max:20'],
            'details.*.label' => ['required', 'string', 'max:64'],
            'details.*.value' => ['required', 'string', 'max:255'],
            'invoice_requisites' => ['sometimes', 'nullable', 'list', 'max:30'],
            'invoice_requisites.*' => ['array:field,label,shown'],
            'invoice_requisites.*.field' => ['nullable', 'string', Rule::in(InvoiceRequisites::FIELDS), 'required_without:invoice_requisites.*.label', 'prohibits:invoice_requisites.*.label'],
            'invoice_requisites.*.label' => ['nullable', 'string', 'max:64', 'required_without:invoice_requisites.*.field'],
            'invoice_requisites.*.shown' => ['required', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
    }

    /**
     * Normalize the IBAN, the SWIFT and the BIC (upper case, no spaces), and a blank name to none.
     *
     * @return array
     */
    protected function overrides(): array
    {
        $overrides = [];

        foreach (['iban', 'swift', 'bic'] as $field) {
            if (is_string($this->input($field))) {
                $overrides[$field] = strtoupper((string) preg_replace('/\s+/', '', $this->input($field)));
            }
        }

        if (is_string($this->input('name'))) {
            $overrides['name'] = trim($this->input('name')) === '' ? null : trim($this->input('name'));
        }

        return $overrides;
    }
}
