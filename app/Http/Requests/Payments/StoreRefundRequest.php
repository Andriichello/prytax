<?php

namespace PryTax\Http\Requests\Payments;

use Core\Http\Requests\Crud\StoreRequest;
use PryTax\Http\Requests\Traits\ScopesToProfile;

/**
 * Class StoreRefundRequest.
 *
 * Records money returned to a client from a receipt.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Requests\Payments
 */
class StoreRefundRequest extends StoreRequest
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
            'amount' => ['required', ...$this->moneyRules()],
            'refunded_at' => ['required', 'date'],
            'from_account_id' => ['required', 'integer', $this->existsInProfile('accounts')],
            'reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'nbu_rate' => ['sometimes', 'nullable', 'numeric', 'decimal:0,4', 'gt:0'],
            'correction_note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Normalize the amount and the rate.
     *
     * @return array
     */
    protected function overrides(): array
    {
        return $this->normalizedMoney(['amount', 'nbu_rate']);
    }
}
