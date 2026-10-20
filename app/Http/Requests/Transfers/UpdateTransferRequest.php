<?php

namespace PryTax\Http\Requests\Transfers;

use Core\Http\Requests\Crud\UpdateRequest;

/**
 * Class UpdateTransferRequest.
 *
 * Updates a transfer; given allocations replace its own.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Requests\Transfers
 */
class UpdateTransferRequest extends UpdateRequest
{
    use TransferRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return $this->transferRules('sometimes');
    }
}
