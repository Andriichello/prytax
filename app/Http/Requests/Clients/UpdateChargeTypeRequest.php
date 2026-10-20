<?php

namespace PryTax\Http\Requests\Clients;

use Core\Http\Requests\Crud\UpdateRequest;
use Illuminate\Validation\Rule;

/**
 * Class UpdateChargeTypeRequest.
 *
 * Updates a charge type of the client.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Requests\Clients
 */
class UpdateChargeTypeRequest extends UpdateRequest
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
                'sometimes',
                'string',
                'max:64',
                Rule::unique('charge_types', 'name')->where('client_id', (int) $this->route('client'))->ignore($this->getId()),
            ],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
