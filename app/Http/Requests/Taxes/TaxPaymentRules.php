<?php

namespace PryTax\Http\Requests\Taxes;

use Illuminate\Validation\Rule;
use PryTax\Enums\TaxAllocation;
use PryTax\Enums\TaxPaymentStatus;
use PryTax\Enums\TaxType;
use PryTax\Http\Requests\Traits\ScopesToProfile;

/**
 * Trait TaxPaymentRules.
 *
 * Rules of a tax payment's fields, which its store and update requests share: the allocation names its receipt,
 * month or quarter; a paid one has its date; the account it was paid from is an active UAH one of the profile.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Requests\Taxes
 */
trait TaxPaymentRules
{
    use ScopesToProfile;

    /**
     * Get the rules of the fields, each prefixed with the given presence rule.
     *
     * @param string $presence
     *
     * @return array
     */
    protected function taxPaymentRules(string $presence): array
    {
        return [
            'tax_type' => [$presence, Rule::enum(TaxType::class)],
            'allocation' => [$presence, Rule::enum(TaxAllocation::class)],
            'payment_id' => ['sometimes', 'nullable', 'required_if:allocation,payment', 'integer', $this->existsInProfile('payments')],
            'period_year' => ['sometimes', 'nullable', 'required_unless:allocation,payment', 'integer', 'min:2000', 'max:2100'],
            'period_quarter' => ['sometimes', 'nullable', 'required_if:allocation,quarter', 'integer', 'between:1,4'],
            'period_month' => ['sometimes', 'nullable', 'required_if:allocation,month', 'integer', 'between:1,12'],
            'amount' => [$presence, ...$this->moneyRules()],
            'status' => ['sometimes', Rule::enum(TaxPaymentStatus::class)],
            'paid_at' => ['sometimes', 'nullable', 'required_if:status,paid', 'date'],
            'paid_from_account_id' => [
                'sometimes',
                'nullable',
                'integer',
                $this->existsInProfile('accounts')->where('currency', 'UAH')->where('is_active', true),
            ],
            'reference' => ['sometimes', 'nullable', 'string', 'max:255'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
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
