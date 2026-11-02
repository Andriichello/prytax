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
 * one account, one currency. Its requisites are its sets' (`RequisiteSetRules`).
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
            'limit_amount' => ['sometimes', 'nullable', ...$this->moneyRules()],
            'limit_period' => ['sometimes', 'nullable', 'required_with:limit_amount', Rule::enum(LimitPeriod::class)],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
    }

    /**
     * Normalize the currency (upper case, no spaces) and the limit.
     *
     * @return array
     */
    protected function overrides(): array
    {
        $overrides = $this->normalizedMoney(['limit_amount']);

        foreach (['currency'] as $field) {
            if (is_string($this->input($field))) {
                $overrides[$field] = strtoupper((string) preg_replace('/\s+/', '', $this->input($field)));
            }
        }

        return $overrides;
    }
}
