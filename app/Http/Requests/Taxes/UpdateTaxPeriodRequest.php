<?php

namespace PryTax\Http\Requests\Taxes;

use Core\Http\Requests\Crud\UpdateRequest;
use Illuminate\Validation\Rule;
use PryTax\Enums\DeclarationStatus;

/**
 * Class UpdateTaxPeriodRequest.
 *
 * Updates a quarter's declaration status and notes. Submitted and accepted lock the quarter (design.md §8.3).
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Requests\Taxes
 */
class UpdateTaxPeriodRequest extends UpdateRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'declaration_status' => ['sometimes', Rule::enum(DeclarationStatus::class)],
            'declaration_submitted_at' => ['sometimes', 'nullable', 'date'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
