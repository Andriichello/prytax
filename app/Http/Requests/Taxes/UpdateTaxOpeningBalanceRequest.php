<?php

namespace PryTax\Http\Requests\Taxes;

use Core\Http\Requests\Crud\UpdateRequest;
use Illuminate\Validation\Rule;
use PryTax\Enums\TaxType;
use PryTax\Http\Requests\Traits\ScopesToProfile;

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
        return [
            'tax_type' => ['sometimes', Rule::enum(TaxType::class)],
            'as_of' => ['sometimes', 'date'],
            'amount' => ['sometimes', 'numeric', 'decimal:0,2'],
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
