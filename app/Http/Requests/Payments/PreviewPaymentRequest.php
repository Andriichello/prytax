<?php

namespace PryTax\Http\Requests\Payments;

use Core\Http\Requests\Crud\StoreRequest;

/**
 * Class PreviewPaymentRequest.
 *
 * Computes what a receipt would be saved with (its quarter, rate, UAH amount, ЄП and ВЗ, and how it covers its
 * invoice), without saving it, for the form's "Буде збережено" block; with `payment_id`, what an update of that receipt
 * would be saved with.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Requests\Payments
 */
class PreviewPaymentRequest extends StoreRequest
{
    use PaymentRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            ...$this->paymentRules('required'),
            'payment_id' => ['sometimes', 'nullable', 'integer', $this->existsInProfile('payments')],
        ];
    }

    /**
     * Get the default values: a receipt of an invoice.
     *
     * @return array
     */
    protected function defaults(): array
    {
        return ['kind' => 'invoice'];
    }
}
