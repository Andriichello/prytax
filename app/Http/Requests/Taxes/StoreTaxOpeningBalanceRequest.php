<?php

namespace PryTax\Http\Requests\Taxes;

use Core\Http\Requests\Crud\StoreRequest;
use Illuminate\Validation\Rule;
use PryTax\Enums\TaxType;
use PryTax\Http\Requests\Traits\ScopesToProfile;

/**
 * Class StoreTaxOpeningBalanceRequest.
 *
 * Creates an opening balance of a tax: positive is overpaid, negative is owed.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Requests\Taxes
 */
class StoreTaxOpeningBalanceRequest extends StoreRequest
{
    use ScopesToProfile;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'tax_type' => ['required', Rule::enum(TaxType::class)],
            // The balance before a quarter, from which the running balance starts (TaxReconciler)
            'as_of' => [
                'required',
                'date',
                'regex:/^\d{4}-(01|04|07|10)-01$/',
                $this->uniqueInProfile('tax_opening_balances', 'as_of')->where('tax_type', $this->input('tax_type')),
            ],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:-9999999999999', 'max:9999999999999'],
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
