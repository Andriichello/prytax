<?php

namespace PryTax\Http\Requests\Invoices;

use Core\Http\Requests\Crud\IndexRequest;
use Illuminate\Validation\Rule;
use PryTax\Enums\InvoiceLocale;
use PryTax\Enums\InvoiceStyle;
use PryTax\Http\Requests\Traits\ScopesToProfile;

/**
 * Class PreviewStyleRequest.
 *
 * Asks for an invoice's PDF in a style (and language), which the style picker shows before the style is chosen:
 * the draft's, the client's latest invoice, or the ФОП's (design.md §4.1). Nothing is saved.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Requests\Invoices
 */
class PreviewStyleRequest extends IndexRequest
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
            'style' => ['required', Rule::enum(InvoiceStyle::class)],
            'locale' => ['sometimes', 'nullable', Rule::enum(InvoiceLocale::class)],
            'client_id' => ['sometimes', 'nullable', 'integer', $this->existsInProfile('clients')],
            'invoice_id' => ['sometimes', 'nullable', 'integer', $this->existsInProfile('invoices')],
        ];
    }
}
