<?php

namespace PryTax\Http\Requests\Clients;

use PryTax\Http\Requests\Traits\ScopesToProfile;

/**
 * Trait ContractRules.
 *
 * Rules of a contract's fields with its hourly rates, whose charge types must be the client's. The client is read
 * from the route, as the parents aren't resolved yet, when the rules are made.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Requests\Clients
 */
trait ContractRules
{
    use ScopesToProfile;

    /**
     * Get the rules of the fields, each prefixed with the given presence rule.
     *
     * @param string $presence
     *
     * @return array
     */
    protected function contractRules(string $presence): array
    {
        return [
            'name' => [$presence, 'string', 'max:255'],
            'number' => ['sometimes', 'nullable', 'string', 'max:64'],
            'started_at' => [$presence, 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date', 'after_or_equal:started_at'],
            'currency' => [$presence, 'string', 'size:3', 'alpha'],
            'payment_terms_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:365'],
            'description_template' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
            'rates' => ['sometimes', 'array', 'max:50'],
            'rates.*.charge_type_id' => [
                'nullable',
                'integer',
                $this->existsInProfile('charge_types')->where('client_id', (int) $this->route('client')),
            ],
            'rates.*.effective_from' => ['required', 'date'],
            'rates.*.hourly_rate' => ['required', ...$this->moneyRules(false)],
        ];
    }

    /**
     * Normalize the currency and the rates.
     *
     * @return array
     */
    protected function overrides(): array
    {
        $overrides = $this->normalizedMoney(['rates.*.hourly_rate']);

        if (is_string($this->input('currency'))) {
            $overrides['currency'] = strtoupper($this->input('currency'));
        }

        return $overrides;
    }
}
