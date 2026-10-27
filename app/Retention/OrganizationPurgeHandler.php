<?php

namespace PryTax\Retention;

use Core\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;
use PryTax\DataLifecycle\Contracts\RetentionFamilyHandler;
use PryTax\DataLifecycle\Retention\Models\RetentionJob;
use PryTax\DataLifecycle\Retention\RetentionResult;
use PryTax\DataLifecycle\Retention\TenantPurger;

/**
 * Class OrganizationPurgeHandler.
 *
 * Purges a deleted organization once its grace is over (backups.md §9.3): every ФОП as a profile purge does (data,
 * documents, backups, the profile's row), then its users (credentials and access, then the rows, through api-core's
 * permanent delete, which hands linked accounts over), then the audit log of the organization (its user-, auth- and
 * organization-level entries), then the organization's row, whose foreign keys take the module's records with it. An
 * organization, which isn't deleted (restored during its grace), is never purged.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Retention
 */
class OrganizationPurgeHandler implements RetentionFamilyHandler
{
    /**
     * OrganizationPurgeHandler constructor.
     *
     * @param TenantPurger $purger
     * @param UserRemovalHandler $users
     */
    public function __construct(
        protected TenantPurger $purger,
        protected UserRemovalHandler $users,
    ) {}

    /**
     * Get the families, which the handler does.
     *
     * @return array<int, string>
     */
    public function families(): array
    {
        return ['organization_profiles', 'organization_users', 'organization_audit', 'organization_finalize'];
    }

    /**
     * Do the family for the deleted organization.
     *
     * @param RetentionJob $job
     *
     * @return RetentionResult
     */
    public function handle(RetentionJob $job): RetentionResult
    {
        $organizationId = (int) $job->run->subject_id;
        $organization = DB::table('organizations')->where('id', $organizationId)->first(['id', 'deleted_at']);

        if ($organization !== null && $organization->deleted_at === null) {
            throw new LogicException("The organization $organizationId isn't deleted; it is never purged.");
        }

        return match ($job->family) {
            'organization_profiles' => $this->profiles($organizationId),
            'organization_users' => $this->users($organizationId),
            'organization_audit', 'organization_finalize' => $this->audit($organizationId, finalize: $job->family === 'organization_finalize'),
        };
    }

    /**
     * Purge every profile of the organization, deleted ones too.
     *
     * @param int $organizationId
     *
     * @return RetentionResult
     */
    protected function profiles(int $organizationId): RetentionResult
    {
        $result = new RetentionResult;

        foreach (DB::table('profiles')->where('organization_id', $organizationId)->pluck('id') as $profileId) {
            $objects = $this->purger->documentsOf((int) $profileId);
            $result->deleted += $this->purger->purgeData((int) $profileId);
            $result->objectsDeleted += $this->purger->deleteObjects($objects);

            [$deleted, $archives] = $this->purger->deleteBackups((int) $profileId);
            $result->deleted += $deleted + $this->purger->deleteRoot((int) $profileId);
            $result->objectsDeleted += $archives;
            $result->processed++;
        }

        return $result;
    }

    /**
     * Delete the organization's users for good, with their credentials, access and notifications.
     *
     * @param int $organizationId
     *
     * @return RetentionResult
     */
    protected function users(int $organizationId): RetentionResult
    {
        $result = new RetentionResult;

        User::query()->withTrashed()->where('organization_id', $organizationId)->lazyById()->each(function (User $user) use ($result) {
            $result->deleted += $this->users->credentials($user->id)->deleted + $this->users->access($user->id)->deleted;
            $result->deleted += DB::table('notifications')->where('notifiable_type', $user->getMorphClass())->where('notifiable_id', $user->id)->delete();

            $user->forceDelete();
            $result->deleted++;
            $result->processed++;
        });

        return $result;
    }

    /**
     * Delete the organization's audit log, and with `finalize` its row too.
     *
     * @param int $organizationId
     * @param bool $finalize
     *
     * @return RetentionResult
     */
    protected function audit(int $organizationId, bool $finalize): RetentionResult
    {
        $deleted = DB::table('audit_logs')->where('organization_id', $organizationId)->delete();

        if ($finalize) {
            $deleted += DB::table('organizations')->where('id', $organizationId)->delete();
        }

        return new RetentionResult(processed: $deleted, deleted: $deleted);
    }
}
