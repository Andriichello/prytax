<?php

namespace PryTax\Http\Requests\Integrations;

use Core\Http\Requests\Traits\WithObjectBody;
use Illuminate\Validation\Rule;

/**
 * Class ConfigureIntegrationRequest.
 *
 * Turning the organization's integration on or off and configuring it: the user its other side reads as (one of the
 * organization's, or null for the one who turned it on) and the ФОП it sees (the organization's own, or null for
 * every one).
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Requests\Integrations
 */
class ConfigureIntegrationRequest extends IntegrationRequest
{
    use WithObjectBody;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'enabled' => ['sometimes', 'boolean'],
            'acting_user_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where('organization_id', $this->orgId())->whereNull('deleted_at'),
            ],
            'profile_ids' => ['sometimes', 'nullable', 'array', 'min:1'],
            'profile_ids.*' => [
                'integer',
                Rule::exists('profiles', 'id')->where('organization_id', $this->orgId())->whereNull('deleted_at'),
            ],
        ];
    }
}
