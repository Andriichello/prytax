<?php

namespace PryTax\Http\Requests\Accounts;

use Core\Http\Requests\Crud\StoreRequest;

/**
 * Class StoreAccountRequest.
 *
 * Creates an account of the profile, with its first set of requisites under `requisites`, which becomes its default.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Requests\Accounts
 */
class StoreAccountRequest extends StoreRequest
{
    use AccountRules {
        overrides as accountOverrides;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            ...$this->accountRules('required'),
            'currency' => ['required', 'string', 'size:3', 'alpha'],
            'requisites' => ['sometimes', 'nullable', 'array'],
            ...RequisiteSetRules::rules('requisites'),
        ];
    }

    /**
     * Normalize the currency, the limit, and the first set's codes and name.
     *
     * @return array
     */
    protected function overrides(): array
    {
        $overrides = $this->accountOverrides();

        if (is_array($this->input('requisites'))) {
            $overrides['requisites'] = RequisiteSetRules::normalized($this->input('requisites'));
        }

        return $overrides;
    }
}
