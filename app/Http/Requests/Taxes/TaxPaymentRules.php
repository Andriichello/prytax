<?php

namespace PryTax\Http\Requests\Taxes;

use Illuminate\Validation\Rule;
use PryTax\Enums\TaxAllocation;
use PryTax\Enums\TaxPaymentStatus;
use PryTax\Enums\TaxType;
use PryTax\Http\Requests\Traits\ScopesToProfile;
use PryTax\Models\Account;

/**
 * Trait TaxPaymentRules.
 *
 * Rules of a tax payment's fields, which its store and update requests share (design.md §8.4): ЄП and ВЗ are paid from
 * a receipt, whose amounts they take, so a typed amount is refused; ЄСВ is paid for a month, with its amount typed. The
 * allocation follows the tax. A paid one has its date; the account it was paid from is one taxes are paid from, and a
 * foreign-currency one needs the amount, which left it.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Requests\Taxes
 */
trait TaxPaymentRules
{
    use ScopesToProfile;

    /**
     * Get the rules of the fields.
     *
     * @return array
     */
    protected function taxPaymentRules(): array
    {
        $esv = TaxType::SocialContribution->value;

        return [
            'tax_type' => ['required', Rule::enum(TaxType::class)],
            'allocation' => ['sometimes', Rule::in([TaxAllocation::Payment->value, TaxAllocation::Month->value])],
            'payment_id' => ["required_unless:tax_type,$esv", "prohibited_if:tax_type,$esv", 'nullable', 'integer', $this->existsInProfile('payments')],
            'period_year' => ["required_if:tax_type,$esv", "prohibited_unless:tax_type,$esv", 'nullable', 'integer', 'min:2000', 'max:2100'],
            'period_month' => ["required_if:tax_type,$esv", "prohibited_unless:tax_type,$esv", 'nullable', 'integer', 'between:1,12'],
            'amount' => ["required_if:tax_type,$esv", "prohibited_unless:tax_type,$esv", ...$this->moneyRules()],
            'status' => ['sometimes', Rule::enum(TaxPaymentStatus::class)],
            'paid_at' => ['sometimes', 'nullable', 'required_if:status,paid', 'date'],
            'paid_from_account_id' => [
                'sometimes',
                'nullable',
                'integer',
                $this->existsInProfile('accounts')->where(fn ($query) => Account::wherePaysTaxes($query)),
            ],
            'original_amount' => ['sometimes', 'nullable', ...$this->moneyRules()],
            'reference' => ['sometimes', 'nullable', 'string', 'max:255'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Normalize the amounts, and allocate the payment by its tax: ЄП and ВЗ to a receipt, ЄСВ to a month.
     *
     * @return array
     */
    protected function overrides(): array
    {
        $overrides = $this->normalizedMoney(['amount', 'original_amount']);
        $type = TaxType::tryFrom((string) $this->input('tax_type'));

        if ($type !== null) {
            $overrides['allocation'] = $type === TaxType::SocialContribution
                ? TaxAllocation::Month->value
                : TaxAllocation::Payment->value;
        }

        return $overrides;
    }
}
