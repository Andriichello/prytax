<?php

namespace PryTax\Http\Requests\Invoices;

use Core\Http\Requests\Crud\ShowRequest;

/**
 * Class PayInvoiceRequest.
 *
 * Marks an invoice as paid, once its receipts cover the total, on the given date or that of its last receipt.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Requests\Invoices
 */
class PayInvoiceRequest extends ShowRequest
{
    /**
     * Ability, which the policy checks.
     *
     * @var string|null
     */
    protected ?string $ability = 'pay';

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'paid_at' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
