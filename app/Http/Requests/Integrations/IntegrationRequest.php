<?php

namespace PryTax\Http\Requests\Integrations;

use Core\Http\Requests\CrudRequest;

/**
 * Class IntegrationRequest.
 *
 * A request, which changes the organization's integration or reaches its key (a new key, its connection, the probe of
 * this computer): its owners' (`configure models.integrations`).
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Requests\Integrations
 */
class IntegrationRequest extends CrudRequest
{
    /**
     * Ability, which the policy checks.
     *
     * @var string|null
     */
    protected ?string $ability = 'configure';

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return [];
    }
}
