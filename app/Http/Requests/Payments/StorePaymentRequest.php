<?php

namespace PryTax\Http\Requests\Payments;

use Core\Http\Requests\Crud\StoreRequest;

/**
 * Class StorePaymentRequest.
 *
 * Records a receipt: of an invoice, an advance or other taxable income.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Requests\Payments
 */
class StorePaymentRequest extends StoreRequest
{
    use PaymentRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return $this->paymentRules('required');
    }

    /**
     * Get the default values: a receipt of an invoice.
     *
     * @return array
     */
    protected function defaults(): array
    {
        return ['kind' => 'invoice'];
    }
}
