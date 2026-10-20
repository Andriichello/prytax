<?php

namespace PryTax\Http\Requests\Clients;

use Core\Http\Requests\Crud\StoreRequest;

/**
 * Class StoreContractRequest.
 *
 * Creates a contract of the client, with its hourly rates.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Requests\Clients
 */
class StoreContractRequest extends StoreRequest
{
    use ContractRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return $this->contractRules('required');
    }
}
