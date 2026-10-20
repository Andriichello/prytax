<?php

namespace PryTax\Http\Requests\Taxes;

use Core\Http\Requests\Crud\UpdateRequest;
use Illuminate\Validation\Rule;
use PryTax\Enums\EsvStatus;

/**
 * Class UpdateEsvObligationRequest.
 *
 * Marks a month of ЄСВ as due or exempt, with a note.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Requests\Taxes
 */
class UpdateEsvObligationRequest extends UpdateRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(EsvStatus::class)],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
