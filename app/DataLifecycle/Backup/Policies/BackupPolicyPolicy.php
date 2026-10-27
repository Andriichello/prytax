<?php

namespace PryTax\DataLifecycle\Backup\Policies;

use Core\Models\BaseModel;
use Core\Policies\CrudPolicy;
use PryTax\DataLifecycle\Backup\Models\BackupPolicy;

/**
 * Class BackupPolicyPolicy.
 *
 * Authorizes the requests of the organization's backup policy: owners and accountants read it (`viewAny`), owners
 * configure it (`configure models.backup-policies`).
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Backup\Policies
 */
class BackupPolicyPolicy extends CrudPolicy
{
    /**
     * Model, which the policy authorizes.
     *
     * @var BaseModel|string
     */
    protected BaseModel|string $model = BackupPolicy::class;

    /**
     * Determine whether the user can configure the organization's policy.
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
