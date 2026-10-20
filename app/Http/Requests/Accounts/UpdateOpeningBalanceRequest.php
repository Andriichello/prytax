<?php

namespace PryTax\Http\Requests\Accounts;

use Core\Http\Requests\Crud\UpdateRequest;
use PryTax\Http\Requests\Traits\ScopesToProfile;

/**
 * Class UpdateOpeningBalanceRequest.
 *
 * Updates an opening balance of an account.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Requests\Accounts
 */
class UpdateOpeningBalanceRequest extends UpdateRequest
{
    use ScopesToProfile;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'as_of' => ['sometimes', 'date'],
            'amount' => ['sometimes', 'numeric', 'decimal:0,2'],
        ];
    }

    /**
     * Normalize the amount.
     *
     * @return array
     */
    protected function overrides(): array
    {
        return $this->normalizedMoney(['amount']);
    }
}
