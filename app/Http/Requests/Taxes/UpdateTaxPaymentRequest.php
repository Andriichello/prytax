<?php

namespace PryTax\Http\Requests\Taxes;

use Core\Http\Requests\Crud\UpdateRequest;

/**
 * Class UpdateTaxPaymentRequest.
 *
 * Updates a tax payment, such as confirming a sent one as paid or failed.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Requests\Taxes
 */
class UpdateTaxPaymentRequest extends UpdateRequest
{
    use TaxPaymentRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return $this->taxPaymentRules();
    }
}
