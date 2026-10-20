<?php

namespace PryTax\Http\Requests\Invoices;

use Core\Http\Requests\Crud\ShowRequest;

/**
 * Class CancelInvoiceRequest.
 *
 * Cancels a ready or sent invoice; its number is never reused.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Requests\Invoices
 */
class CancelInvoiceRequest extends ShowRequest
{
    /**
     * Ability, which the policy checks.
     *
     * @var string|null
     */
    protected ?string $ability = 'cancel';

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'cancelled_at' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
