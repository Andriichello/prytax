<?php

namespace PryTax\Http\Requests\Accounts;

use Illuminate\Validation\Rule;
use PryTax\Services\Accounts\InvoiceRequisites;

/**
 * Class RequisiteSetRules.
 *
 * Rules of a set of an account's requisites, which its store and update requests share, and a new account's store
 * request, under `requisites`, for its first set: its name (in an invoice's parentheses), the requisites, SWIFT and
 * BIC apart, its own details, their order in invoices and which of them print (`InvoiceRequisites`), whether it's the
 * default and whether it's offered.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Requests\Accounts
 */
final class RequisiteSetRules
{
    /**
     * Get the rules of the set's fields, under the prefix (`requisites` of a new account) or at the top.
     *
     * @param string $prefix
     *
     * @return array<string, array>
     */
    public static function rules(string $prefix = ''): array
    {
        $at = $prefix === '' ? '' : "$prefix.";
        $code = ['sometimes', 'nullable', 'string', 'regex:/^[A-Z0-9]{8}([A-Z0-9]{3})?$/'];

        return [
            "{$at}name" => ['sometimes', 'nullable', 'string', 'max:64'],
            "{$at}holder_name" => ['sometimes', 'nullable', 'string', 'max:255'],
            "{$at}bank_name" => ['sometimes', 'nullable', 'string', 'max:255'],
            "{$at}iban" => ['sometimes', 'nullable', 'string', 'regex:/^[A-Z]{2}[0-9A-Z]{13,32}$/'],
            "{$at}account_number" => ['sometimes', 'nullable', 'string', 'max:64'],
            "{$at}swift" => $code,
            "{$at}bic" => $code,
            "{$at}details" => ['sometimes', 'nullable', 'array', 'max:20'],
            "{$at}details.*.label" => ['required', 'string', 'max:64'],
            "{$at}details.*.value" => ['required', 'string', 'max:255'],
            "{$at}invoice_requisites" => ['sometimes', 'nullable', 'list', 'max:30'],
            "{$at}invoice_requisites.*" => ['array:field,label,shown'],
            "{$at}invoice_requisites.*.field" => ['nullable', 'string', Rule::in(InvoiceRequisites::FIELDS), "required_without:{$at}invoice_requisites.*.label", "prohibits:{$at}invoice_requisites.*.label"],
            "{$at}invoice_requisites.*.label" => ['nullable', 'string', 'max:64', "required_without:{$at}invoice_requisites.*.field"],
            "{$at}invoice_requisites.*.shown" => ['required', 'boolean'],
            "{$at}is_default" => ['sometimes', 'boolean'],
            "{$at}is_active" => ['sometimes', 'boolean'],
            "{$at}sort_order" => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
    }

    /**
     * Get the set's input normalized: the IBAN, the SWIFT and the BIC upper case without spaces, a blank name none.
     *
     * @param array $input
     *
     * @return array
     */
    public static function normalized(array $input): array
    {
        foreach (['iban', 'swift', 'bic'] as $field) {
            if (is_string($input[$field] ?? null)) {
                $input[$field] = strtoupper((string) preg_replace('/\s+/', '', $input[$field]));
            }
        }

        if (is_string($input['name'] ?? null)) {
            $input['name'] = trim($input['name']) === '' ? null : trim($input['name']);
        }

        return $input;
    }
}
