<?php

namespace PryTax\Http\Requests\Invoices;

use Core\Http\Requests\Crud\StoreRequest;

/**
 * Class StoreInvoiceRequest.
 *
 * Creates a draft invoice of the profile with its items: a generated one, or an external one, whose PDF is uploaded
 * next and whose total is entered.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Requests\Invoices
 */
class StoreInvoiceRequest extends StoreRequest
{
    use InvoiceRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return $this->invoiceRules('required');
    }
}
