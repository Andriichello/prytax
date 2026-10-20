<?php

namespace PryTax\Http\Requests\Invoices;

use Core\Http\Requests\Crud\StoreRequest;

/**
 * Class SetCounterRequest.
 *
 * Moves an invoice counter of the profile forward to the next number it allocates, such as after importing invoices
 * numbered elsewhere.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Requests\Invoices
 */
class SetCounterRequest extends StoreRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'scope_key' => ['required', 'string', 'max:64'],
            'next_number' => ['required', 'integer', 'min:1', 'max:4294967295'],
        ];
    }
}
