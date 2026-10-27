<?php

namespace PryTax\DataLifecycle\Backup;

use Carbon\CarbonInterface;
use Core\Helpers\CurrentOrganization;
use PryTax\DataLifecycle\Backup\Enums\BackupKind;
use PryTax\DataLifecycle\Backup\Jobs\RunOrganizationBackup;
use PryTax\DataLifecycle\Backup\Models\BackupPolicy;
use PryTax\DataLifecycle\Backup\Models\BackupRun;

/**
 * Class BackupRuns.
 *
 * Starts the runs of an organization (backups.md §2.2, §4.3): scheduled ones with the policy's options and profiles,
 * manual ones of the selected profiles, automatic (safety) ones of a profile, which is about to change for good, and
 * period exports. Each is recorded first, then its job is dispatched for the organization; an automatic one can run
 * at once, so the change waits for it.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Backup
 */
class BackupRuns
{
    /**
     * Start a scheduled run of the policy.
     *
     * @param BackupPolicy $policy
     *
     * @return BackupRun
     */
    public function scheduled(BackupPolicy $policy): BackupRun
    {
        return $this->start($policy->organization_id, BackupKind::Scheduled, [
            ...$policy->options(),
            'skip_unchanged' => $policy->skip_unchanged,
        ], $policy->profiles_mode === BackupPolicy::SELECTED_PROFILES ? $policy->profileIds() : null);
    }

    /**
     * Start a manual run of the profiles (all of them, when null), with the policy's options.
     *
     * @param int $organizationId
     * @param array<int, int>|null $profileIds
     * @param int|null $userId
     *
     * @return BackupRun
     */
    public function manual(int $organizationId, ?array $profileIds, ?int $userId): BackupRun
    {
        return $this->start($organizationId, BackupKind::Manual, BackupPolicy::of($organizationId)->options(), $profileIds, $userId);
    }

    /**
     * Start an automatic run of a profile, which is about to change for good, such as before it is deleted.
     *
     * @param int $organizationId
     * @param int $profileId
     * @param string $reason
     * @param bool $now Run it in this process, so the change waits for it.
     *
     * @return BackupRun
     */
    public function automatic(int $organizationId, int $profileId, string $reason, bool $now = false): BackupRun
    {
        $options = [...BackupPolicy::of($organizationId)->options(), RunSpec::INCLUDE_DOCUMENTS => true];

        return $this->start($organizationId, BackupKind::Automatic, $options, [$profileId], null, $reason, now: $now);
    }

    /**
     * Start an export of a profile's period, which isn't restorable (backups.md §2.7).
     *
     * @param int $organizationId
     * @param int $profileId
     * @param CarbonInterface $from
     * @param CarbonInterface $to
     * @param int|null $userId
     *
     * @return BackupRun
     */
    public function export(int $organizationId, int $profileId, CarbonInterface $from, CarbonInterface $to, ?int $userId): BackupRun
    {
        $options = BackupPolicy::of($organizationId)->options();
        $scope = ['type' => 'period', 'from' => $from->toDateString(), 'to' => $to->toDateString()];

        return $this->start($organizationId, BackupKind::Export, $options, [$profileId], $userId, scope: $scope);
    }

    /**
     * Record a run, and dispatch its job for the organization.
     *
     * @param int $organizationId
     * @param BackupKind $kind
     * @param array<string, bool> $options
     * @param array<int, int>|null $profileIds
     * @param int|null $userId
     * @param string|null $reason
     * @param array{type: string, from?: string, to?: string} $scope
     * @param bool $now
     *
     * @return BackupRun
     */
    protected function start(
        int $organizationId,
        BackupKind $kind,
        array $options,
        ?array $profileIds,
        ?int $userId = null,
        ?string $reason = null,
        array $scope = ['type' => 'full'],
        bool $now = false,
    ): BackupRun {
        $run = new BackupRun;
        $run->forceFill([
            'organization_id' => $organizationId,
            'kind' => $kind,
            'scope' => $scope,
            'options' => $options,
            'profile_ids' => $profileIds === null ? null : array_values(array_map('intval', $profileIds)),
            'triggered_by_user_id' => $userId,
            'reason' => $reason,
        ])->save();

        $now
            ? CurrentOrganization::run($organizationId, fn () => RunOrganizationBackup::dispatchSync($run->id))
            : RunOrganizationBackup::dispatchFor($organizationId, $run->id);

        return $run;
    }
}
