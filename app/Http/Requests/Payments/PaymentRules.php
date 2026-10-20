<?php

namespace PryTax\Http\Requests\Payments;

use Illuminate\Validation\Rule;
use PryTax\Enums\PaymentKind;
use PryTax\Http\Requests\Traits\ScopesToProfile;

/**
 * Trait PaymentRules.
 *
 * Rules of a receipt's fields, which its store, update and preview requests share. A receipt of an invoice takes
 * the invoice's currency and the account's received currency; an advance or other income names its currency. A
 * manual `nbu_rate` is for when the NBU's rate is unavailable, and a `correction_note` for a locked quarter.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Requests\Payments
 */
trait PaymentRules
{
    use ScopesToProfile;

    /**
     * Get the rules of the fields, each prefixed with the given presence rule.
     *
     * @param string $presence
     *
     * @return array
     */
    protected function paymentRules(string $presence): array
    {
        return [
            'kind' => ['sometimes', Rule::in([PaymentKind::Invoice->value, PaymentKind::Advance->value, PaymentKind::Other->value])],
            'invoice_id' => ['sometimes', 'nullable', 'required_if:kind,invoice', 'integer', $this->existsInProfile('invoices')],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3', 'alpha'],
            'amount' => [$presence, ...$this->moneyRules()],
            'client_fee_amount' => ['sometimes', 'nullable', ...$this->moneyRules(false)],
            'received_to_account_id' => [$presence, 'integer', $this->existsInProfile('accounts')],
            'received_amount' => ['sometimes', 'nullable', ...$this->moneyRules()],
            'received_at' => [$presence, 'date', 'before_or_equal:' . now()->addDay()->toDateString()],
            'nbu_rate' => ['sometimes', 'nullable', 'numeric', 'decimal:0,4', 'gt:0'],
            'receipt_group_id' => ['sometimes', 'nullable', 'uuid'],
            'correction_note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Normalize the amounts, the rate and the currency.
     *
     * @return array
     */
    protected function overrides(): array
    {
        $overrides = $this->normalizedMoney(['amount', 'client_fee_amount', 'received_amount', 'nbu_rate']);

        if (is_string($this->input('currency'))) {
            $overrides['currency'] = strtoupper($this->input('currency'));
        }

        return $overrides;
    }
}
