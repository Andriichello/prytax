<?php

namespace PryTax\DataLifecycle\Backup\Policies;

use Core\Models\BaseModel;
use Core\Policies\CrudPolicy;
use PryTax\DataLifecycle\Backup\Enums\BackupKind;
use PryTax\DataLifecycle\Backup\Models\Backup;

/**
 * Class BackupPolicy.
 *
 * Authorizes the requests of backups (backups.md §4.7, D7): the `{ability} models.backups` permission of the user's
 * role first (owners and accountants list and download them; viewers have none), then: only an archive is
 * downloaded, and only a manual one is deleted by hand, as retention keeps the others.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Backup\Policies
 */
class BackupPolicy extends CrudPolicy
{
    /**
     * Model, which the policy authorizes.
     *
     * @var BaseModel|string
     */
    protected BaseModel|string $model = Backup::class;

    /**
     * Determine whether the user can download the backup's archive.
     *
     * @param mixed $user
     * @param Backup $model
     *
     * @return bool
     */
    public function download(mixed $user, mixed $model): bool
    {
        return $this->hasAnyRole($user) && $model->hasArchive();
    }

    /**
     * Determine whether the user can restore a profile from its archive, as a new profile of the organization.
     *
     * @param mixed $user
     *
     * @return bool
     */
    public function import(mixed $user): bool
    {
        return $this->hasAnyRole($user);
    }

    /**
     * Determine whether the user can delete the backup: a manual archive only.
     *
     * @param mixed $user
     * @param Backup $model
     *
     * @return bool
     */
    public function delete(mixed $user, mixed $model): bool
    {
        return parent::delete($user, $model) && $model->tier === BackupKind::Manual && $model->hasArchive();
    }
}
