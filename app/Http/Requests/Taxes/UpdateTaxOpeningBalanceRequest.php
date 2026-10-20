<?php

namespace PryTax\Http\Requests\Taxes;

use Core\Http\Requests\Crud\UpdateRequest;
use Illuminate\Validation\Rule;
use PryTax\Enums\TaxType;
use PryTax\Http\Requests\Traits\ScopesToProfile;
use PryTax\Models\TaxOpeningBalance;

/**
 * Class UpdateTaxOpeningBalanceRequest.
 *
 * Updates an opening balance of a tax.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Requests\Taxes
 */
class UpdateTaxOpeningBalanceRequest extends UpdateRequest
{
    use ScopesToProfile;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        $balance = $this->target(TaxOpeningBalance::class);
        $taxType = $this->input('tax_type', $balance?->tax_type?->value);

        return [
            'tax_type' => ['sometimes', Rule::enum(TaxType::class)],
            // The balance before a quarter, from which the running balance starts (TaxReconciler)
            'as_of' => [
                'sometimes',
                'date',
                'regex:/^\d{4}-(01|04|07|10)-01$/',
                $this->uniqueInProfile('tax_opening_balances', 'as_of')->where('tax_type', $taxType)->ignore($this->getId()),
            ],
            'amount' => ['sometimes', 'numeric', 'decimal:0,2', 'min:-9999999999999', 'max:9999999999999'],
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
