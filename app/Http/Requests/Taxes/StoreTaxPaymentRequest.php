<?php

namespace PryTax\Http\Requests\Taxes;

use Core\Http\Requests\Crud\StoreRequest;
use PryTax\Enums\TaxPaymentStatus;

/**
 * Class StoreTaxPaymentRequest.
 *
 * Records a payment of ЄП, ВЗ or ЄСВ, sent by default (pending until confirmed paid).
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Requests\Taxes
 */
class StoreTaxPaymentRequest extends StoreRequest
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

    /**
     * Get the default values: a sent payment.
     *
     * @return array
     */
    protected function defaults(): array
    {
        return ['status' => TaxPaymentStatus::Sent->value];
    }
}
