<?php

namespace PryTax\Http\Requests\Auth;

use Core\Http\Requests\BaseRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Class RegisterRequest.
 *
 * Registers a new organization with its owner from the sign-in page: the organization's name, the owner's name,
 * e-mail and password (repeated), and their language. An e-mail, which any account has, deleted ones included,
 * is refused: that person signs in, or is invited, instead. One person's installation asks for the password alone.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Requests\Auth
 */
class RegisterRequest extends BaseRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array|string>
     */
    public function rules(): array
    {
        // One person's installation makes up its organization and e-mail (builds.md §1.4)
        $single = (bool) config('prytax.single_organization');

        return [
            ...($single ? [] : [
                'organization' => ['required', 'string', 'max:255'],
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            ]),
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
            'locale' => ['sometimes', 'string', Rule::in(config('api-core.locales'))],
            'remember' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Get the messages of the rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => __('An account with this e-mail exists already. Sign in instead.'),
        ];
    }

    /**
     * Get the values, which replace the input's: the names trimmed and the e-mail in lower case, as it's stored.
     *
     * @return array<string, mixed>
     */
    protected function overrides(): array
    {
        return array_map(
            fn (mixed $value) => is_string($value) ? trim($value) : $value,
            array_filter([
                'organization' => $this->input('organization'),
                'name' => $this->input('name'),
                'email' => is_string($this->input('email')) ? Str::lower($this->input('email')) : $this->input('email'),
            ], fn (mixed $value) => $value !== null),
        );
    }
}
