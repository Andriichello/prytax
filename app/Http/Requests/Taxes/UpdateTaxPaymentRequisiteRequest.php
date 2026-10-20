<?php

namespace PryTax\Http\Requests\Taxes;

use Core\Http\Requests\Crud\UpdateRequest;
use Illuminate\Validation\Rule;
use PryTax\Enums\TaxType;

/**
 * Class UpdateTaxPaymentRequisiteRequest.
 *
 * Updates budget requisites of a tax.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Requests\Taxes
 */
class UpdateTaxPaymentRequisiteRequest extends UpdateRequest
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
            'iban' => ['sometimes', 'string', 'regex:/^UA[0-9]{27}$/'],
            'recipient_name' => ['sometimes', 'string', 'max:255'],
            'recipient_code' => ['sometimes', 'string', 'regex:/^[0-9]{8,10}$/'],
            'purpose_template' => ['sometimes', 'string', 'max:255'],
            'effective_from' => ['sometimes', 'date'],
        ];
    }

    /**
     * Normalize the IBAN (upper case, no spaces).
     *
     * @return array
     */
    protected function overrides(): array
    {
        return is_string($this->input('iban'))
            ? ['iban' => strtoupper((string) preg_replace('/\s+/', '', $this->input('iban')))]
            : [];
    }
}
