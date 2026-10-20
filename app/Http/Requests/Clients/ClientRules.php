<?php

namespace PryTax\Http\Requests\Clients;

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
    use ScopesToProfile;

    /**
     * Get the rules of the fields, each prefixed with the given presence rule.
     *
     * @param string $presence
     *
     * @return array
     */
    protected function clientRules(string $presence): array
    {
        return [
            'name' => [$presence, 'string', 'max:255'],
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'name_uk' => ['sometimes', 'nullable', 'string', 'max:255'],
            'legal_name_uk' => ['sometimes', 'nullable', 'string', 'max:255'],
            'country' => ['sometimes', 'nullable', 'string', 'size:2', 'alpha'],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3', 'alpha'],
            'vat_number' => ['sometimes', 'nullable', 'string', 'max:32'],
            'address' => ['sometimes', 'nullable', 'array'],
            'address.lines' => ['sometimes', 'array', 'max:4'],
            'address.lines.*' => ['nullable', 'string', 'max:255'],
            'address.city' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address.state' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address.zip' => ['sometimes', 'nullable', 'string', 'max:32'],
            'tax_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'invoice_footer_note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Normalize the country and the currency (upper case).
     *
     * @return array
     */
    protected function overrides(): array
    {
        $overrides = [];

        foreach (['country', 'currency'] as $field) {
            if (is_string($this->input($field))) {
                $overrides[$field] = strtoupper($this->input($field));
            }
        }

        return $overrides;
    }
}
