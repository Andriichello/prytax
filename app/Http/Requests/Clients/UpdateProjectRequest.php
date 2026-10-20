<?php

namespace PryTax\Http\Requests\Clients;

use Core\Http\Requests\Crud\UpdateRequest;
use Illuminate\Validation\Rule;

/**
 * Class UpdateProjectRequest.
 *
 * Updates a project of the client.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Requests\Clients
 */
class UpdateProjectRequest extends UpdateRequest
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
                'sometimes',
                'string',
                'max:32',
                Rule::unique('projects', 'code')->where('client_id', (int) $this->route('client'))->ignore($this->getId()),
            ],
            'name' => ['sometimes', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
