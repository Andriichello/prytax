<?php

namespace PryTax\Http\Requests\Clients;

use Core\Http\Requests\Crud\UpdateRequest;

/**
 * Class UpdateContractRequest.
 *
 * Updates a contract; given rates replace its own.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Requests\Clients
 */
class UpdateContractRequest extends UpdateRequest
{
    use ContractRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return $this->contractRules('sometimes');
    }
}
