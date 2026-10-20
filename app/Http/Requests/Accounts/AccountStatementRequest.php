<?php

namespace PryTax\Http\Requests\Accounts;

use Core\Http\Requests\Crud\ShowRequest;

/**
 * Class AccountStatementRequest.
 *
 * Shows the statement of an account in a year (the current one by default).
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Requests\Accounts
 */
class AccountStatementRequest extends ShowRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'year' => ['sometimes', 'integer', 'min:2000', 'max:2100'],
        ];
    }
}
