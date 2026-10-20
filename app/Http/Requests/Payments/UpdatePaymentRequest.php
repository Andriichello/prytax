<?php

namespace PryTax\Http\Requests\Payments;

use Core\Http\Requests\Crud\UpdateRequest;

/**
 * Class UpdatePaymentRequest.
 *
 * Updates a receipt; a change of its amount, currency or date recomputes its snapshot. Giving an advance an
 * invoice links it to the invoice.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Requests\Payments
 */
class UpdatePaymentRequest extends UpdateRequest
{
    use PaymentRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return $this->paymentRules('sometimes');
    }
}
