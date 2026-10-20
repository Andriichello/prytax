<?php

namespace PryTax\Http\Requests\Transfers;

use PryTax\Http\Requests\Traits\ScopesToProfile;

/**
 * Trait TransferRules.
 *
 * Rules of a transfer's fields and allocations, which its store and update requests share. Whether the fee and
 * the allocations fit, TransferRecorder checks.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Requests\Transfers
 */
trait TransferRules
{
    use ScopesToProfile;

    /**
     * Get the rules of the fields, each prefixed with the given presence rule.
     *
     * @param string $presence
     *
     * @return array
     */
    protected function transferRules(string $presence): array
    {
        return [
            'from_account_id' => [$presence, 'integer', $this->existsInProfile('accounts')],
            'to_account_id' => [$presence, 'integer', 'different:from_account_id', $this->existsInProfile('accounts')],
            'amount_sent' => [$presence, ...$this->moneyRules()],
            'amount_received' => [$presence, ...$this->moneyRules(false)],
            'fee_amount' => ['sometimes', 'nullable', ...$this->moneyRules(false)],
            'nbu_rate' => ['sometimes', 'nullable', 'numeric', 'decimal:0,4', 'gt:0'],
            'sent_at' => ['sometimes', 'nullable', 'date'],
            'received_at' => [$presence, 'date'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:255'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'allocations' => ['sometimes', 'array', 'max:200'],
            'allocations.*.payment_id' => ['required', 'integer', $this->existsInProfile('payments')],
            'allocations.*.amount' => ['required', ...$this->moneyRules(false)],
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
        return $this->normalizedMoney(['amount_sent', 'amount_received', 'fee_amount', 'nbu_rate', 'allocations.*.amount']);
    }
}
