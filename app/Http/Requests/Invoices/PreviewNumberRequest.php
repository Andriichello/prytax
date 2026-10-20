<?php

namespace PryTax\Http\Requests\Invoices;

use Core\Http\Requests\Crud\IndexRequest;
use PryTax\Http\Requests\Traits\ScopesToProfile;

/**
 * Class PreviewNumberRequest.
 *
 * Previews the number, which a new invoice of the client and dates would get when frozen, without allocating it.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Requests\Invoices
 */
class PreviewNumberRequest extends IndexRequest
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
            'period_end' => ['required', 'date'],
            'issued_at' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
