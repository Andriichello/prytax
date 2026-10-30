<?php

namespace PryTax\Http\Requests\Clients;

use PryTax\Http\Requests\Traits\InvoiceSettingRules;
use PryTax\Http\Requests\Traits\ScopesToProfile;

/**
 * Trait ClientRules.
 *
 * Rules of a client's fields, which its store and update requests share.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Requests\Clients
 */
trait ClientRules
{
    use InvoiceSettingRules;
    use ScopesToProfile;

    /**
     * Get the rules of the fields, each prefixed with the given presence rule.
     *
     * @param string $presence
     * @param mixed $clientId The client, which is updated: its own abbreviation isn't a duplicate.
     *
     * @return array
     */
    protected function clientRules(string $presence, mixed $clientId = null): array
    {
        return [
            'name' => [$presence, 'string', 'max:255'],
            'abbreviation' => [
                $presence,
                'string',
                'max:10',
                'regex:/^[A-Z0-9]+$/',
                $this->uniqueInProfile('clients', 'abbreviation')->ignore($clientId),
            ],
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'name_uk' => ['sometimes', 'nullable', 'string', 'max:255'],
            'legal_name_uk' => ['sometimes', 'nullable', 'string', 'max:255'],
            'country' => ['sometimes', 'nullable', 'string', 'size:2', 'alpha'],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3', 'alpha'],
            'vat_number' => ['sometimes', 'nullable', 'string', 'max:32'],
            // The address as the invoices print it, line by line; its parts of before aren't taken any more
            'address' => ['sometimes', 'nullable', 'array:text'],
            'address.text' => ['required_with:address', 'string', 'max:1000'],
            'tax_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            ...$this->invoiceSettingRules('invoice_'),
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Normalize the country, the currency and the abbreviation (upper case).
     *
     * @return array
     */
    protected function overrides(): array
    {
        $overrides = [];

        foreach (['country', 'currency', 'abbreviation'] as $field) {
            if (is_string($this->input($field))) {
                $overrides[$field] = strtoupper($this->input($field));
            }
        }

        return $overrides;
    }
}
