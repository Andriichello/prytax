<?php

namespace PryTax\Http\Requests\Accounts;

use Core\Http\Requests\Crud\StoreRequest;
use PryTax\Http\Requests\Traits\ScopesToProfile;

/**
 * Class OpeningBalanceRequest.
 *
 * Creates an opening balance of an account: its balance on a date, from which its flows are counted.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Requests\Accounts
 */
class OpeningBalanceRequest extends StoreRequest
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
            'as_of' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'decimal:0,2'],
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
