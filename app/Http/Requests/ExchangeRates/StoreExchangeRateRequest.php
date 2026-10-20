<?php

namespace PryTax\Http\Requests\ExchangeRates;

use Core\Http\Requests\Crud\StoreRequest;

/**
 * Class StoreExchangeRateRequest.
 *
 * Stores a rate, which a user entered, when the NBU's rate of the day is unavailable. An NBU rate, which is cached
 * already, is kept.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Requests\ExchangeRates
 */
class StoreExchangeRateRequest extends StoreRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'currency' => ['required', 'string', 'size:3', 'alpha', 'not_in:UAH'],
            'rate_date' => ['required', 'date'],
            'rate' => ['required', 'numeric', 'decimal:0,4', 'gt:0'],
        ];
    }

    /**
     * Normalize the currency.
     *
     * @return array
     */
    protected function overrides(): array
    {
        return is_string($this->input('currency')) ? ['currency' => strtoupper($this->input('currency'))] : [];
    }
}
