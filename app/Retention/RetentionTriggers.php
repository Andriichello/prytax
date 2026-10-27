<?php

namespace PryTax\Retention;

use Core\Models\Organization;
use Core\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use PryTax\DataLifecycle\Backup\BackupRuns;
use PryTax\DataLifecycle\Retention\Events\RetentionRunCancelled;
use PryTax\DataLifecycle\Retention\Models\RetentionRun;
use PryTax\DataLifecycle\Retention\RetentionRuns;
use PryTax\Models\Profile;

/**
 * Class RetentionTriggers.
 *
 * Plans the deletions of PryTax's subjects (backups.md §9.3). A deleted ФОП (soft-deleted, so hidden and out of the
 * schedules at once) gets its final backup and a purge after the grace, which restoring it cancels. A user removed
 * through api-core's delete loses its credentials and access and is anonymized at once. A deleted organization (its
 * users soft-deleted with it by api-core) gets the final backups of its ФОП and a purge after the grace, which
 * restoring it cancels. A run cancelled by ops restores its subject.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Retention
 */
class RetentionTriggers
{
    /**
     * Families of a ФОП's purge, in their order.
     *
     * @var array<int, string>
     */
    public const array PROFILE_FAMILIES = ['accounting', 'documents', 'profile_finalize'];

    /**
     * Families of a user's removal, in their order.
     *
     * @var array<int, string>
     */
    public const array USER_FAMILIES = ['credentials', 'access', 'user_pii'];

    /**
     * Families of an organization's purge, in their order.
     *
     * @var array<int, string>
     */
    public const array ORGANIZATION_FAMILIES = ['organization_profiles', 'organization_users', 'organization_audit', 'organization_finalize'];

    /**
     * RetentionTriggers constructor.
     *
     * @param RetentionRuns $runs
     * @param BackupRuns $backups
     */
    public function __construct(
        protected RetentionRuns $runs,
        protected BackupRuns $backups,
    ) {}

    /**
     * Register the listeners of the subjects' deletions.
     *
     * @return void
     */
    public function listen(): void
    {
        Profile::softDeleted(fn (Profile $profile) => $this->profileDeleted($profile));
        Profile::restored(fn (Profile $profile) => $this->runs->cancelOf('profile', $profile->id));
        User::softDeleted(fn (User $user) => $this->userRemoved($user));
        Organization::softDeleted(fn (Organization $organization) => $this->organizationDeleted($organization));
        Organization::restored(fn (Organization $organization) => $this->runs->cancelOf('organization', $organization->id));
    }

    /**
     * Back up the deleted ФОП and plan its purge after the grace.
     *
     * @param Profile $profile
     *
     * @return void
     */
    public function profileDeleted(Profile $profile): void
    {
        $this->backups->automatic($profile->organization_id, $profile->id, 'profile_deleted');
        $this->runs->plan('profile', $profile->id, $profile->organization_id, 'profile_deleted', self::PROFILE_FAMILIES, $this->graceDays(), Auth::id());
    }

    /**
     * Remove the user's credentials and access, and anonymize it, now.
     *
     * @param User $user
     *
     * @return void
     */
    public function userRemoved(User $user): void
    {
        $this->runs->plan('user', $user->id, $user->organization_id, 'user_removed', self::USER_FAMILIES, 0, Auth::id(), ['user_pii' => 'anonymize']);
    }

    /**
     * Back up the deleted organization's ФОП, and plan its purge after the grace.
     *
     * @param Organization $organization
     *
     * @return void
     */
    public function organizationDeleted(Organization $organization): void
    {
        $profiles = DB::table('profiles')->where('organization_id', $organization->id)->whereNull('deleted_at')->pluck('id');

        foreach ($profiles as $profileId) {
            $this->backups->automatic($organization->id, (int) $profileId, 'organization_deleted');
        }

        $this->runs->plan('organization', $organization->id, $organization->id, 'organization_deleted', self::ORGANIZATION_FAMILIES, $this->graceDays(), Auth::id());
    }

    /**
     * Restore the subject of a run, which ops cancelled.
     *
     * @param RetentionRunCancelled $event
     *
     * @return void
     */
    public function runCancelled(RetentionRunCancelled $event): void
    {
        $run = RetentionRun::query()->find($event->runId);

        match ($run?->subject_type) {
            'profile' => Profile::query()->withoutGlobalScopes()->onlyTrashed()->find($run->subject_id)?->restore(),
            'organization' => Organization::query()->onlyTrashed()->find($run->subject_id)?->restore(),
            default => null,
        };
    }

    /**
     * Get the days of grace.
     *
     * @return int
     */
    protected function graceDays(): int
    {
        return (int) config('data-lifecycle.retention.grace_days');
    }
}
