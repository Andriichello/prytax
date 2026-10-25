<?php

namespace PryTax\Http\Requests\Payments;

use Illuminate\Validation\Rule;
use PryTax\Enums\PaymentKind;
use PryTax\Http\Requests\Traits\ScopesToProfile;

/**
 * Trait PaymentRules.
 *
 * Rules of a receipt's fields, which its store, update and preview requests share. Every receipt belongs to an
 * invoice, whose currency it takes, and lands in the account's currency. A payment without an invoice names its
 * client, contract and service period in `virtual_invoice`, which becomes its virtual invoice (design.md §4.2). A
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
        $creating = $presence === 'required';

        return [
            'kind' => ['sometimes', Rule::in([PaymentKind::Invoice->value])],
            'invoice_id' => [$creating ? 'required_without:virtual_invoice' : 'sometimes', 'nullable', 'integer', $this->existsInProfile('invoices')],
            'virtual_invoice' => ['sometimes', 'array:client_id,contract_id,period_start,period_end,notes', ...($creating ? ['prohibits:invoice_id'] : [])],
            'virtual_invoice.client_id' => ['required_with:virtual_invoice', 'integer', $this->existsInProfile('clients')],
            'virtual_invoice.contract_id' => [
                'required_with:virtual_invoice',
                'integer',
                $this->existsInProfile('contracts')->where('client_id', (int) $this->input('virtual_invoice.client_id')),
            ],
            'virtual_invoice.period_start' => ['required_with:virtual_invoice', 'date'],
            'virtual_invoice.period_end' => ['required_with:virtual_invoice', 'date', 'after_or_equal:virtual_invoice.period_start'],
            'virtual_invoice.notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
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
     * Normalize the amounts and the rate.
     *
     * @return array
     */
    protected function overrides(): array
    {
        return $this->normalizedMoney(['amount', 'client_fee_amount', 'received_amount', 'nbu_rate']);
    }
}
