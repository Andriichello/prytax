<?php

namespace PryTax\Http\Requests\Payments;

use Core\Http\Requests\Crud\StoreRequest;
use PryTax\Http\Requests\Traits\ScopesToProfile;

/**
 * Class GroupPaymentsRequest.
 *
 * Records one wire, which covers several invoices of one currency: the invoiced amount of each, and what landed on
 * the account in total, which is split in proportion to them.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Requests\Payments
 */
class GroupPaymentsRequest extends StoreRequest
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
            'receipts' => ['required', 'array', 'min:2', 'max:50'],
            'receipts.*.invoice_id' => ['required', 'integer', 'distinct', $this->existsInProfile('invoices')],
            'receipts.*.amount' => ['required', ...$this->moneyRules()],
            'received_to_account_id' => ['required', 'integer', $this->existsInProfile('accounts')],
            'received_amount' => ['sometimes', 'nullable', ...$this->moneyRules()],
            'client_fee_amount' => ['sometimes', 'nullable', ...$this->moneyRules(false)],
            'received_at' => ['required', 'date', 'before_or_equal:' . now()->addDay()->toDateString()],
            'nbu_rate' => ['sometimes', 'nullable', 'numeric', 'decimal:0,4', 'gt:0'],
            'correction_note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Normalize the amounts and the rate.
     *
     * @return array
     */
    protected function overrides(): array
    {
        return $this->normalizedMoney(['receipts.*.amount', 'received_amount', 'client_fee_amount', 'nbu_rate']);
    }
}
