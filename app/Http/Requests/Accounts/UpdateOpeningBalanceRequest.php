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
            'as_of' => [
                'sometimes',
                'date',
                $this->uniqueInProfile('account_opening_balances', 'as_of')
                    ->where('account_id', (int) $this->route('account'))
                    ->ignore($this->getId()),
            ],
            'amount' => ['sometimes', 'numeric', 'decimal:0,2', 'min:-9999999999999', 'max:9999999999999'],
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
