<?php

namespace PryTax\Http\Requests\Clients;

use Core\Http\Requests\Crud\StoreRequest;
use Illuminate\Validation\Rule;

/**
 * Class StoreProjectRequest.
 *
 * Creates a project of the client, whose code is unique for the client.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Requests\Clients
 */
class StoreProjectRequest extends StoreRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'code' => [
                'required',
                'string',
                'max:32',
                Rule::unique('projects', 'code')->where('client_id', (int) $this->route('client')),
            ],
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
