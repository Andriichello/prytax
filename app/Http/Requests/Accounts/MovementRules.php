<?php

namespace PryTax\Http\Requests\Accounts;

use Illuminate\Validation\Rule;
use PryTax\Enums\MovementCategory;
use PryTax\Enums\MovementDirection;
use PryTax\Http\Requests\Traits\ScopesToProfile;

/**
 * Trait MovementRules.
 *
 * Rules of a non-taxable movement of an account.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Requests\Accounts
 */
trait MovementRules
{
    use ScopesToProfile;

    /**
     * Get the rules of the fields, each prefixed with the given presence rule.
     *
     * @param string $presence
     *
     * @return array
     */
    protected function movementRules(string $presence): array
    {
        return [
            'direction' => [$presence, Rule::enum(MovementDirection::class)],
            'amount' => [$presence, ...$this->moneyRules()],
            'booked_at' => [$presence, 'date'],
            'category' => [$presence, Rule::enum(MovementCategory::class)],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Normalize the amount.
     *
     * @return array
     */
    protected function overrides(): array
    {
        return $this->normalizedMoney(['amount']);
    }
}
