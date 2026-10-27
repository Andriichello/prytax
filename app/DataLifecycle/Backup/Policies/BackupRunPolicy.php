<?php

namespace PryTax\DataLifecycle\Backup\Policies;

use Core\Models\BaseModel;
use Core\Policies\CrudPolicy;
use PryTax\DataLifecycle\Backup\Enums\RunStatus;
use PryTax\DataLifecycle\Backup\Models\BackupRun;

/**
 * Class BackupRunPolicy.
 *
 * Authorizes the requests of backup runs: listing them, starting a manual one, and building the organization's
 * bundle of a finished one, by the `{ability} models.backup-runs` permissions of owners and accountants.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Backup\Policies
 */
class BackupRunPolicy extends CrudPolicy
{
    /**
     * Model, which the policy authorizes.
     *
     * @var BaseModel|string
     */
    protected BaseModel|string $model = BackupRun::class;

    /**
     * Determine whether the user can build or download the run's bundle: a finished run's, which has archives.
     *
     * @param mixed $user
     * @param BackupRun $model
     *
     * @return bool
     */
    public function bundle(mixed $user, mixed $model): bool
    {
        return $this->hasAnyRole($user) && in_array($model->status, [RunStatus::Completed, RunStatus::Partial], true);
    }

    /**
     * Determine whether the user can export a profile's period.
     *
     * @param mixed $user
     *
     * @return bool
     */
    public function export(mixed $user): bool
    {
        return $this->hasAnyRole($user);
    }
}
