<?php

namespace PryTax\Http\Requests\Accounts;

use Core\Http\Requests\Crud\UpdateRequest;

/**
 * Class UpdateRequisiteSetRequest.
 *
 * Updates a set of an account's requisites: its requisites, their order in invoices, whether it's the default, and
 * whether it's offered.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Requests\Accounts
 */
class UpdateRequisiteSetRequest extends UpdateRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return RequisiteSetRules::rules();
    }

    /**
     * Normalize the codes and the name.
     *
     * @return array
     */
    protected function overrides(): array
    {
        return array_intersect_key(RequisiteSetRules::normalized($this->all()), array_flip(['iban', 'swift', 'bic', 'name']));
    }
}
