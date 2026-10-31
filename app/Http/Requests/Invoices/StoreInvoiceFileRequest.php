<?php

namespace PryTax\Http\Requests\Invoices;

use Core\Http\Requests\Files\StoreFileRequest;

/**
 * Class StoreInvoiceFileRequest.
 *
 * Uploads a file of an invoice, as api-core's upload does, and, with `primary`, makes it the draft's document in place
 * of the PDF uploaded before. Its rules list `primary` too, so Claude finds it among the endpoints' fields.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Requests\Invoices
 */
class StoreInvoiceFileRequest extends StoreFileRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'primary' => ['sometimes', 'boolean'],
        ];
    }
}
