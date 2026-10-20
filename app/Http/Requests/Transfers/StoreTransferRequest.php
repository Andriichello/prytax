<?php

namespace PryTax\Http\Requests\Transfers;

use Core\Http\Requests\Crud\StoreRequest;

/**
 * Class StoreTransferRequest.
 *
 * Records a transfer between two accounts of the profile, with the receipts it carries.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Requests\Transfers
 */
class StoreTransferRequest extends StoreRequest
{
    use TransferRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return $this->transferRules('required');
    }
}
