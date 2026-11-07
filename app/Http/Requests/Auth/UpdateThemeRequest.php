<?php

namespace PryTax\Http\Requests\Auth;

use Core\Http\Requests\BaseRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use PryTax\Enums\Theme;

/**
 * Class UpdateThemeRequest.
 *
 * Updates the theme of the signed-in user's own screens.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Requests\Auth
 */
class UpdateThemeRequest extends BaseRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            'theme' => ['required', 'string', Rule::enum(Theme::class)],
        ];
    }
}
