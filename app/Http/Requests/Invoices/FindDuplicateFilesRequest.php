<?php

namespace PryTax\Http\Requests\Invoices;

use Core\Http\Requests\Crud\IndexRequest;

/**
 * Class FindDuplicateFilesRequest.
 *
 * Asks which invoices of the profile have a file with the SHA-256 already, so an external PDF is checked before its
 * invoice is created.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Requests\Invoices
 */
class FindDuplicateFilesRequest extends IndexRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'sha256' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
        ];
    }

    /**
     * Normalize the hash to lowercase.
     *
     * @return array
     */
    protected function overrides(): array
    {
        return is_string($this->input('sha256')) ? ['sha256' => strtolower($this->input('sha256'))] : [];
    }
}
