<?php

namespace PryTax\Http\Requests\Invoices;

use Illuminate\Validation\Rule;
use PryTax\Enums\InvoiceSource;
use PryTax\Enums\ItemUnit;
use PryTax\Http\Requests\Traits\InvoiceSettingRules;
use PryTax\Http\Requests\Traits\ScopesToProfile;

/**
 * Trait InvoiceRules.
 *
 * Rules of a draft invoice's fields and items, which its store and update requests share. Whether the contract and
 * the account fit the client and the currency, InvoiceWriter checks.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Requests\Invoices
 */
trait InvoiceRules
{
    use InvoiceSettingRules;
    use ScopesToProfile;

    /**
     * Get the rules of the fields, each prefixed with the given presence rule.
     *
     * @param string $presence
     *
     * @return array
     */
    protected function invoiceRules(string $presence): array
    {
        return [
            'source' => ['sometimes', Rule::enum(InvoiceSource::class)],
            'client_id' => [$presence, 'integer', $this->existsInProfile('clients')],
            'contract_id' => ['sometimes', 'nullable', 'integer', $this->existsInProfile('contracts')],
            'receiving_account_id' => ['sometimes', 'nullable', 'integer', $this->existsInProfile('accounts')],
            'number' => ['sometimes', 'nullable', 'string', 'max:64'],
            'period_start' => [$presence, 'date'],
            'period_end' => [$presence, 'date', 'after_or_equal:period_start'],
            'issued_at' => ['sometimes', 'nullable', 'date'],
            'due_at' => ['sometimes', 'nullable', 'date'],
            'currency' => ['sometimes', 'string', 'size:3', 'alpha'],
            'adjustment_amount' => ['sometimes', 'numeric', 'decimal:0,2', 'min:-9999999999999', 'max:9999999999999'],
            'adjustment_label' => ['sometimes', 'nullable', 'string', 'max:255'],
            'total' => ['sometimes', 'required_if:source,external', ...$this->moneyRules(false)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            ...$this->invoiceSettingRules('locale'),
            'items' => ['sometimes', 'array', 'max:100'],
            'items.*.project_id' => ['nullable', 'integer'],
            'items.*.charge_type_id' => ['nullable', 'integer'],
            'items.*.description' => ['nullable', 'string', 'max:2000'],
            'items.*.unit' => ['sometimes', Rule::enum(ItemUnit::class)],
            'items.*.quantity' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:99999999'],
            'items.*.unit_price' => ['nullable', ...$this->moneyRules(false)],
        ];
    }

    /**
     * Normalize the amounts and the currency.
     *
     * @return array
     */
    protected function overrides(): array
    {
        $overrides = $this->normalizedMoney([
            'adjustment_amount', 'total', 'items.*.quantity', 'items.*.unit_price',
        ]);

        if (is_string($this->input('currency'))) {
            $overrides['currency'] = strtoupper($this->input('currency'));
        }

        if (is_string($this->input('number'))) {
            $overrides['number'] = trim($this->input('number')) === '' ? null : trim($this->input('number'));
        }

        return $overrides;
    }
}
