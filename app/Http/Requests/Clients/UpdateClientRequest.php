<?php

namespace PryTax\Http\Requests\Clients;

use Core\Http\Requests\Crud\UpdateRequest;

/**
 * Class UpdateClientRequest.
 *
 * Updates the given fields of a client, such as deactivating it.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Requests\Clients
 */
class UpdateClientRequest extends UpdateRequest
{
    use ClientRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return $this->clientRules('sometimes');
    }
}
