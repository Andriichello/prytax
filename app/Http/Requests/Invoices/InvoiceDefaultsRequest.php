<?php

namespace PryTax\Http\Requests\Invoices;

use Core\Http\Requests\Crud\IndexRequest;
use PryTax\Http\Requests\Traits\ScopesToProfile;

/**
 * Class InvoiceDefaultsRequest.
 *
 * Asks what the form of a new invoice of the client (and contract and dates) starts with, before it is saved.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Requests\Invoices
 */
class InvoiceDefaultsRequest extends IndexRequest
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
            'client_id' => ['required', 'integer', $this->existsInProfile('clients')],
            'contract_id' => ['sometimes', 'nullable', 'integer', $this->existsInProfile('contracts')->where('client_id', $this->input('client_id'))],
            'period_start' => ['sometimes', 'nullable', 'date'],
            'period_end' => ['sometimes', 'nullable', 'date'],
            'issued_at' => ['sometimes', 'nullable', 'date'],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3', 'alpha'],
        ];
    }
}
