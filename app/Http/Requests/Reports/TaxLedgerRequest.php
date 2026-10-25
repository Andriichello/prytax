<?php

namespace PryTax\Http\Requests\Reports;

use Core\Http\Requests\Crud\IndexRequest;
use Illuminate\Validation\Rule;
use PryTax\Enums\TaxType;
use PryTax\Services\Taxes\TaxLedger;

/**
 * Class TaxLedgerRequest.
 *
 * Shows the tax ledger of a profile's year (design.md §8.5), narrowed to some of its quarters (`quarter`, none is
 * the whole year), taxes (`tax_type`) and statuses (`status`: `unpaid`, `failed`, `sent`, `paid`). Each is a list,
 * as an array or comma-separated, as the SPA sends it (`quarter=3,4`).
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Requests\Reports
 */
class TaxLedgerRequest extends IndexRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'quarter' => ['sometimes', 'array'],
            'quarter.*' => ['integer', 'between:1,4'],
            'tax_type' => ['sometimes', 'array'],
            'tax_type.*' => [Rule::enum(TaxType::class)],
            'status' => ['sometimes', 'array'],
            'status.*' => [Rule::in(TaxLedger::STATUSES)],
        ];
    }

    /**
     * Split the comma-separated lists into arrays.
     *
     * @return array
     */
    protected function overrides(): array
    {
        $overrides = [];

        foreach (['quarter', 'tax_type', 'status'] as $field) {
            if (is_string($this->input($field))) {
                $overrides[$field] = array_values(array_filter(explode(',', $this->input($field)), fn (string $value) => $value !== ''));
            }
        }

        return $overrides;
    }
}
