<?php

namespace PryTax\Http\Requests\Accounts;

use Core\Http\Requests\Crud\UpdateRequest;

/**
 * Class UpdateMovementRequest.
 *
 * Updates a non-taxable movement of an account.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Requests\Accounts
 */
class UpdateMovementRequest extends UpdateRequest
{
    use MovementRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return $this->movementRules('sometimes');
    }
}
