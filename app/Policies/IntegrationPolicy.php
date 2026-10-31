<?php

namespace PryTax\Policies;

use Core\Models\BaseModel;
use Core\Policies\CrudPolicy;
use PryTax\Models\Integration;

/**
 * Class IntegrationPolicy.
 *
 * Authorizes the requests of the organization's integrations (builds.md §7): owners see them (`viewAny`), turn them on
 * and off, configure them, and get their connections and keys (`configure`); nobody else has either.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Policies
 */
class IntegrationPolicy extends CrudPolicy
{
    /**
     * Model, which the policy authorizes.
     *
     * @var BaseModel|string
     */
    protected BaseModel|string $model = Integration::class;

    /**
     * Determine whether the user can turn the integrations on and off, and configure them: with the permission, which
     * the request checks.
     *
     * @param mixed $user
     *
     * @return bool
     */
    public function configure(mixed $user): bool
    {
        return $this->hasAnyRole($user);
    }
}
