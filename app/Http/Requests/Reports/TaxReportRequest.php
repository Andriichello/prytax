<?php

namespace PryTax\Http\Requests\Reports;

use Core\Http\Requests\Crud\IndexRequest;
use Illuminate\Validation\Rule;
use PryTax\Enums\TaxType;

/**
 * Class TaxReportRequest.
 *
 * Shows a report of a profile's taxes, of one tax when it names `tax_type` (ЄП by default).
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Requests\Reports
 */
class TaxReportRequest extends IndexRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'tax_type' => ['sometimes', Rule::enum(TaxType::class)],
        ];
    }
}
