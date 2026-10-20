<?php

namespace PryTax\Http\Requests\Taxes;

use Core\Http\Requests\Crud\IndexRequest;
use Illuminate\Validation\Rule;
use PryTax\Enums\TaxType;

/**
 * Class CurrentRequisiteRequest.
 *
 * Asks for the requisites of a tax in effect on a date (today by default), with the purpose of a payment for a
 * quarter or a month, for the copy-ready block of the tax payment's form.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Requests\Taxes
 */
class CurrentRequisiteRequest extends IndexRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'tax_type' => ['required', Rule::enum(TaxType::class)],
            'date' => ['sometimes', 'nullable', 'date'],
            'period_year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'period_quarter' => ['required_without:period_month', 'nullable', 'integer', 'between:1,4'],
            'period_month' => ['sometimes', 'nullable', 'integer', 'between:1,12'],
        ];
    }
}
