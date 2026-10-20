<?php

namespace PryTax\Http\Requests\Clients;

use Core\Http\Requests\Crud\StoreRequest;

/**
 * Class StoreClientRequest.
 *
 * Creates a client of the profile.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Requests\Clients
 */
class StoreClientRequest extends StoreRequest
{
    use ClientRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return $this->clientRules('required');
    }
}
