<?php

namespace PryTax\Http\Requests\Accounts;

use Core\Http\Requests\Crud\UpdateRequest;

/**
 * Class UpdateAccountRequest.
 *
 * Updates the given fields of an account, whose currency is fixed.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Requests\Accounts
 */
class UpdateAccountRequest extends UpdateRequest
{
    use AccountRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return $this->accountRules('sometimes');
    }
}
