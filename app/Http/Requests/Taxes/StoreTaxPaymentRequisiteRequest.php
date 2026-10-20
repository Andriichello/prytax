<?php

namespace PryTax\Http\Requests\Taxes;

use Core\Http\Requests\Crud\StoreRequest;
use Illuminate\Validation\Rule;
use PryTax\Enums\TaxType;
use PryTax\Http\Requests\Traits\ScopesToProfile;

/**
 * Class StoreTaxPaymentRequisiteRequest.
 *
 * Creates the budget requisites of a tax from a date.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Requests\Taxes
 */
class StoreTaxPaymentRequisiteRequest extends StoreRequest
{
    use ScopesToProfile;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'tax_type' => ['required', Rule::enum(TaxType::class)],
            'iban' => ['required', 'string', 'regex:/^UA[0-9]{27}$/'],
            'recipient_name' => ['required', 'string', 'max:255'],
            'recipient_code' => ['required', 'string', 'regex:/^[0-9]{8,10}$/'],
            'purpose_template' => ['required', 'string', 'max:255'],
            'effective_from' => [
                'required',
                'date',
                $this->uniqueInProfile('tax_payment_requisites', 'effective_from')->where('tax_type', $this->input('tax_type')),
            ],
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
