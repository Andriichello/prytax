<?php

namespace PryTax\Policies;

use Core\Models\BaseModel;
use Core\Models\User;
use Core\Policies\CrudPolicy;
use PryTax\Models\Profile;

/**
 * Class ProfilePolicy.
 *
 * Authorizes the requests of the profiles (ФОП) of the organization: the `{ability} models.*` permission of the user's role first (see the
 * roles of config/api-core.php), and then CrudPolicy's rules.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Policies
 */
class ProfilePolicy extends CrudPolicy
{
    /**
     * Model, which the policy authorizes.
     *
     * @var BaseModel|string
     */
    protected BaseModel|string $model = Profile::class;

    /**
     * Determine whether the user can configure the profile: change its settings and its signature, and see its full
     * tax id. Changing its identity, its tax id or whether it is active is `update`, which only owners have.
     *
     * @param User|null $user
     * @param Profile $model
     *
     * @return bool
     */
    public function configure(mixed $user, mixed $model): bool
    {
        return $this->hasAnyRole($user);
    }

    /**
     * Determine whether the user can configure the profile, with the permission checked first, as a request is.
     *
     * @param User|null $user
     * @param Profile $model
     *
     * @return bool
     */
    public function canConfigure(mixed $user, Profile $model): bool
    {
        return (bool) ($this->before($user, 'configure') ?? $this->configure($user, $model));
    }
}
