<?php

namespace PryTax\DataLifecycle\Backup;

use PryTax\DataLifecycle\Backup\Enums\BackupKind;
use PryTax\DataLifecycle\Backup\Enums\BackupStatus;
use PryTax\DataLifecycle\Backup\Models\Backup;
use PryTax\DataLifecycle\Backup\Models\BackupPolicy;
use PryTax\DataLifecycle\Backup\Models\BackupRun;

/**
 * Class BackupState.
 *
 * The state of the current organization's backups (backups.md §4.8), which Резервні копії shows and Claude reads
 * (builds.md §7): the policy (on, how often, its next run, its time, how many monthly ones it keeps), the latest run
 * but an export, the newest completed archive of each profile (when, how many profiles, their bytes), and how many
 * manual runs today still allows. It runs in the organization, whose records its scope selects.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Backup
 */
final class BackupState
{
    /**
     * Get the state of the organization's backups.
     *
     * @param int $organizationId The current organization's.
     *
     * @return array{
     *     policy: array{enabled: bool, frequency: mixed, next_run_at: string|null, time_of_day: string, retention_monthly: int},
     *     latest_run: BackupRun|null,
     *     last_completed_at: string|null,
     *     profiles: int,
     *     bytes: int,
     *     manual_runs_left: int,
     * }
     */
    public function of(int $organizationId): array
    {
        $policy = BackupPolicy::of($organizationId);
        $latest = BackupRun::query()
            ->where('kind', '!=', BackupKind::Export)
            ->with('backups')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
        $newest = Backup::query()
            ->where('status', BackupStatus::Completed)
            ->where('tier', '!=', BackupKind::Export)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get(['id', 'profile_id', 'bytes', 'created_at'])
            ->unique('profile_id');
        $today = BackupRun::query()->where('kind', BackupKind::Manual)->where('created_at', '>=', now()->startOfDay())->count();

        return [
            'policy' => [
                'enabled' => $policy->enabled,
                'frequency' => $policy->frequency,
                'next_run_at' => $policy->enabled ? ($policy->next_run_at ?? $policy->nextRunAfter(now()))->toIso8601String() : null,
                'time_of_day' => $policy->nextRunAfter(now())->format('H:i'),
                'retention_monthly' => $policy->retention_monthly,
            ],
            'latest_run' => $latest,
            'last_completed_at' => $newest->max('created_at')?->toIso8601String(),
            'profiles' => $newest->count(),
            'bytes' => (int) $newest->sum('bytes'),
            'manual_runs_left' => max(0, (int) config('data-lifecycle.backups.manual_per_day') - $today),
        ];
    }
}
