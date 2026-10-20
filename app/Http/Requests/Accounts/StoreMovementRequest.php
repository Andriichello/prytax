<?php

namespace PryTax\Http\Requests\Accounts;

use Core\Http\Requests\Crud\StoreRequest;

/**
 * Class StoreMovementRequest.
 *
 * Creates a non-taxable movement of an account.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Requests\Accounts
 */
class StoreMovementRequest extends StoreRequest
{
    use MovementRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return $this->movementRules('required');
    }
}
