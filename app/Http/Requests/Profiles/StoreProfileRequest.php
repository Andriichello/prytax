<?php

namespace PryTax\Http\Requests\Profiles;

use Core\Http\Requests\Crud\StoreRequest;

/**
 * Class StoreProfileRequest.
 *
 * Creates a profile (ФОП) in the organization.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Requests\Profiles
 */
class StoreProfileRequest extends StoreRequest
{
    use ProfileRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return $this->profileRules('required');
    }
}
