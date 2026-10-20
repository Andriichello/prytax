<?php

namespace PryTax\Http\Requests\Profiles;

use Core\Http\Requests\Crud\UpdateRequest;

/**
 * Class UpdateProfileRequest.
 *
 * Updates the given fields of a profile.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Requests\Profiles
 */
class UpdateProfileRequest extends UpdateRequest
{
    use ProfileRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return $this->profileRules('sometimes', (int) $this->getId());
    }
}
