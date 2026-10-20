<?php

namespace PryTax\Http\Requests\Transfers;

use Core\Http\Requests\Crud\StoreRequest;
use PryTax\Http\Requests\Traits\ScopesToProfile;

/**
 * Class ProposeAllocationsRequest.
 *
 * Proposes the receipts, which a transfer of an amount out of an account carries: an exact match and FIFO.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Requests\Transfers
 */
class ProposeAllocationsRequest extends StoreRequest
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
            'from_account_id' => ['required', 'integer', $this->existsInProfile('accounts')],
            'amount_sent' => ['required', ...$this->moneyRules()],
            'transfer_id' => ['sometimes', 'nullable', 'integer', $this->existsInProfile('account_transfers')],
        ];
    }

    /**
     * Normalize the amount.
     *
     * @return array
     */
    protected function overrides(): array
    {
        return $this->normalizedMoney(['amount_sent']);
    }
}
