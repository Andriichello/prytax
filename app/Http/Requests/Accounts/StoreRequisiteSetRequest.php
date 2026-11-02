<?php

namespace PryTax\Http\Requests\Accounts;

use Core\Http\Requests\Crud\StoreRequest;

/**
 * Class StoreRequisiteSetRequest.
 *
 * Creates a set of an account's requisites; an account's first set is its default.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Requests\Accounts
 */
class StoreRequisiteSetRequest extends StoreRequest
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
     * Normalize the IBAN, the code, the name and the labels.
     *
     * @return array
     */
    protected function overrides(): array
    {
        return array_intersect_key(RequisiteSetRules::normalized($this->all()), array_flip(['iban', 'swift', 'name', 'labels']));
    }
}
