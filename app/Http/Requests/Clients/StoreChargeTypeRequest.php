<?php

namespace PryTax\Http\Requests\Clients;

use Core\Http\Requests\Crud\StoreRequest;
use Illuminate\Validation\Rule;

/**
 * Class StoreChargeTypeRequest.
 *
 * Creates a charge type of the client, whose name is unique for the client.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Requests\Clients
 */
class StoreChargeTypeRequest extends StoreRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:64',
                Rule::unique('charge_types', 'name')->where('client_id', (int) $this->route('client')),
            ],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
