<?php

namespace PryTax\Http\Requests\Invoices;

use Core\Http\Requests\Crud\ShowRequest;

/**
 * Class SendInvoiceRequest.
 *
 * Marks a ready invoice as sent, on the given date or today.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Requests\Invoices
 */
class SendInvoiceRequest extends ShowRequest
{
    /**
     * Ability, which the policy checks.
     *
     * @var string|null
     */
    protected ?string $ability = 'send';

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'sent_at' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
