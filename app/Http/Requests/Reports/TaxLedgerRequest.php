<?php

namespace PryTax\Http\Requests\Reports;

use Core\Http\Requests\Crud\IndexRequest;
use Illuminate\Validation\Rule;
use PryTax\Enums\TaxType;
use PryTax\Services\Taxes\TaxLedger;

/**
 * Class TaxLedgerRequest.
 *
 * Shows the tax ledger of a profile's year (design.md §8.5), narrowed to some of its quarters (`quarter[]`, none is
 * the whole year), taxes (`tax_type[]`) and statuses (`status[]`: `unpaid`, `failed`, `sent`, `paid`).
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
}
