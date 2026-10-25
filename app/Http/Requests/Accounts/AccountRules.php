<?php

namespace PryTax\Http\Requests\Accounts;

use Illuminate\Validation\Rule;
use PryTax\Enums\AccountKind;
use PryTax\Enums\LimitPeriod;
use PryTax\Http\Requests\Traits\ScopesToProfile;

/**
 * Trait AccountRules.
 *
 * Rules of an account's fields, which its store and update requests share. The currency is set on creation only:
 * one account, one currency.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Requests\Accounts
 */
trait AccountRules
{
    use ScopesToProfile;

    /**
     * Get the rules of the fields, each prefixed with the given presence rule.
     *
     * @param string $presence
     *
     * @return array
     */
    protected function accountRules(string $presence): array
    {
        return [
            'name' => [$presence, 'string', 'max:255'],
            'kind' => [$presence, Rule::enum(AccountKind::class)],
            'holder_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'bank_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'iban' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Z]{2}[0-9A-Z]{13,32}$/'],
            'swift' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Z0-9]{8}([A-Z0-9]{3})?$/'],
            'account_number' => ['sometimes', 'nullable', 'string', 'max:64'],
            'details' => ['sometimes', 'nullable', 'array', 'max:20'],
            'details.*.label' => ['required', 'string', 'max:64'],
            'details.*.value' => ['required', 'string', 'max:255'],
            'limit_amount' => ['sometimes', 'nullable', ...$this->moneyRules()],
            'limit_period' => ['sometimes', 'nullable', 'required_with:limit_amount', Rule::enum(LimitPeriod::class)],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
    }

    /**
     * Normalize the IBAN and SWIFT (upper case, no spaces), the currency and the limit.
     *
     * @return array
     */
    protected function overrides(): array
    {
        $overrides = $this->normalizedMoney(['limit_amount']);

        foreach (['iban', 'swift', 'currency'] as $field) {
            if (is_string($this->input($field))) {
                $overrides[$field] = strtoupper((string) preg_replace('/\s+/', '', $this->input($field)));
            }
        }

        return $overrides;
    }
}
