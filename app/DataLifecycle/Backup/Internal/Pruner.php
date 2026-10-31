<?php

namespace PryTax\DataLifecycle\Backup\Internal;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use PryTax\DataLifecycle\Backup\Enums\BackupKind;
use PryTax\DataLifecycle\Backup\Enums\BackupStatus;
use PryTax\DataLifecycle\Backup\Models\Backup;
use PryTax\DataLifecycle\Backup\Models\BackupPolicy;
use PryTax\DataLifecycle\Backup\Models\BackupRun;

/**
 * Class Pruner.
 *
 * Retention of the archives (backups.md §4.3, D8). Of a profile's scheduled backups, grandfather-father-son is chosen
 * now, from `created_at`, so a change of the policy applies to the backups there are: the newest of each of the last
 * `retention_daily` days, which have one, of the last `retention_weekly` ISO weeks and of the last
 * `retention_monthly` months. Manual backups stay, 20 an organization at most (the oldest go); automatic ones and
 * exports until they expire. The newest archive of a profile always stays. A pruned backup's object is deleted and its
 * record kept as `pruned`. Bundles and other temporary objects go after a day.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Backup\Internal
 */
final class Pruner
{
    /**
     * Pruner constructor.
     *
     * @param ArchiveStore $store
     */
    public function __construct(private readonly ArchiveStore $store) {}

    /**
     * Prune the archives of every organization, and the expired temporary objects.
     *
     * @return int The archives pruned.
     */
    public function prune(): int
    {
        $pruned = 0;

        $organizations = Backup::query()->withoutGlobalScopes()
            ->where('status', BackupStatus::Completed)
            ->distinct()
            ->pluck('organization_id');

        foreach ($organizations as $organizationId) {
            $pruned += $this->pruneOrganization((int) $organizationId);
        }

        $this->pruneBundles();
        $this->pruneTemporary();

        return $pruned;
    }

    /**
     * Prune the archives of an organization.
     *
     * @param int $organizationId
     *
     * @return int
     */
    public function pruneOrganization(int $organizationId): int
    {
        $policy = BackupPolicy::of($organizationId);
        $backups = Backup::query()->withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('status', BackupStatus::Completed)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        $keep = [];

        foreach ($backups->groupBy('profile_id') as $ofProfile) {
            $kept = $ofProfile->whereIn('tier', [BackupKind::Scheduled, BackupKind::Manual, BackupKind::Automatic]);

            if ($kept->isNotEmpty()) {
                $keep[$kept->first()->id] = true;
            }

            foreach ($this->grandfatherFatherSon($ofProfile->where('tier', BackupKind::Scheduled), $policy) as $id) {
                $keep[$id] = true;
            }
        }

        $manual = $backups->where('tier', BackupKind::Manual)->values();

        foreach ($manual->take((int) config('data-lifecycle.backups.manual_cap')) as $backup) {
            $keep[$backup->id] = true;
        }

        foreach ($backups->whereIn('tier', [BackupKind::Automatic, BackupKind::Export]) as $backup) {
            if ($backup->expires_at === null || $backup->expires_at->isFuture()) {
                $keep[$backup->id] = true;
            }
        }

        $pruned = 0;

        foreach ($backups as $backup) {
            if (!isset($keep[$backup->id])) {
                $this->pruneBackup($backup);
                $pruned++;
            }
        }

        return $pruned;
    }

    /**
     * Estimate how long each completed backup of an organization stays under its current policy (backups.md §4.7):
     * the newest of a profile and the manual ones stay until deleted (`until` null); automatic ones and exports until
     * they expire; a scheduled one, which grandfather-father-son keeps, until its last day, week or month falls out of
     * its window as the schedule goes on (the latest of them, with its `reason`), or else until the next prune.
     *
     * @param int $organizationId
     * @param CarbonImmutable|null $now
     *
     * @return array<string, array{until: string|null, reason: string}> By backup id; `until` is a `Y-m-d`.
     */
    public function keepUntil(int $organizationId, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $timezone = config('app.timezone');
        $policy = BackupPolicy::of($organizationId);
        $backups = Backup::query()->withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('status', BackupStatus::Completed)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        $estimates = [];

        foreach ($backups->groupBy('profile_id') as $ofProfile) {
            $newest = $ofProfile->whereIn('tier', [BackupKind::Scheduled, BackupKind::Manual, BackupKind::Automatic])->first();
            $kept = $this->scheduledKeepers($ofProfile->where('tier', BackupKind::Scheduled), $policy);

            foreach ($ofProfile as $backup) {
                $estimates[$backup->id] = match (true) {
                    $backup->id === $newest?->id => ['until' => null, 'reason' => 'newest'],
                    $backup->tier === BackupKind::Manual => ['until' => null, 'reason' => 'manual'],
                    $backup->tier === BackupKind::Scheduled => isset($kept[$backup->id])
                        ? $kept[$backup->id]
                        : ['until' => $now->setTimezone($timezone)->toDateString(), 'reason' => 'next_prune'],
                    default => ['until' => $backup->expires_at?->setTimezone($timezone)->toDateString(), 'reason' => 'expires'],
                };
            }
        }

        return $estimates;
    }

    /**
     * Get the scheduled backups, which grandfather-father-son keeps, each with the last day it stays: the start of its
     * day, ISO week or month plus the window, less a day, the latest of the windows, which keep it.
     *
     * @param Collection<int, Backup> $backups Newest first.
     * @param BackupPolicy $policy
     *
     * @return array<string, array{until: string, reason: string}>
     */
    private function scheduledKeepers(Collection $backups, BackupPolicy $policy): array
    {
        $timezone = config('app.timezone');
        $windows = [
            'day' => [$policy->retention_daily, fn (CarbonImmutable $at) => $at->startOfDay(), fn (CarbonImmutable $start, int $count) => $start->addDays($count)],
            'week' => [$policy->retention_weekly, fn (CarbonImmutable $at) => $at->startOfWeek(CarbonInterface::MONDAY), fn (CarbonImmutable $start, int $count) => $start->addWeeks($count)],
            'month' => [$policy->retention_monthly, fn (CarbonImmutable $at) => $at->startOfMonth(), fn (CarbonImmutable $start, int $count) => $start->addMonthsNoOverflow($count)],
        ];
        $kept = [];

        foreach ($windows as $reason => [$count, $startOf, $end]) {
            $newest = [];

            foreach ($backups as $backup) {
                $start = $startOf(CarbonImmutable::instance($backup->created_at)->setTimezone($timezone));
                $newest[$start->toDateString()] ??= [$backup->id, $start];
            }

            foreach (array_slice(array_values($newest), 0, $count) as [$id, $start]) {
                $until = $end($start, $count)->subDay()->toDateString();

                if (!isset($kept[$id]) || $until > $kept[$id]['until']) {
                    $kept[$id] = ['until' => $until, 'reason' => $reason];
                }
            }
        }

        return $kept;
    }

    /**
     * Delete a backup's archive, keeping its record as pruned.
     *
     * @param Backup $backup
     *
     * @return void
     */
    public function pruneBackup(Backup $backup): void
    {
        if ($backup->disk !== null && $backup->object_path !== null) {
            $this->store->delete($backup->disk, $backup->object_path);
        }

        $backup->forceFill(['status' => BackupStatus::Pruned, 'pruned_at' => now()])->saveQuietly();
    }

    /**
     * Choose the scheduled backups, which grandfather-father-son keeps: the newest of each bucket, in the last buckets,
     * which have one.
     *
     * @param Collection<int, Backup> $backups Newest first.
     * @param BackupPolicy $policy
     *
     * @return array<int, string>
     */
    private function grandfatherFatherSon(Collection $backups, BackupPolicy $policy): array
    {
        $timezone = config('app.timezone');
        $buckets = [
            [$policy->retention_daily, fn (CarbonImmutable $at) => $at->format('Y-m-d')],
            [$policy->retention_weekly, fn (CarbonImmutable $at) => $at->format('o-\WW')],
            [$policy->retention_monthly, fn (CarbonImmutable $at) => $at->format('Y-m')],
        ];
        $keep = [];

        foreach ($buckets as [$count, $bucketOf]) {
            $newest = [];

            foreach ($backups as $backup) {
                $bucket = $bucketOf(CarbonImmutable::instance($backup->created_at)->setTimezone($timezone));
                $newest[$bucket] ??= $backup->id;
            }

            $keep = [...$keep, ...array_slice(array_values($newest), 0, $count)];
        }

        return $keep;
    }

    /**
     * Delete the organizations' bundles, which expired.
     *
     * @return void
     */
    private function pruneBundles(): void
    {
        BackupRun::query()->withoutGlobalScopes()
            ->whereNotNull('bundle_path')
            ->where('bundle_expires_at', '<', now())
            ->each(function (BackupRun $run) {
                $this->store->delete((string) $run->bundle_disk, (string) $run->bundle_path);
                $run->forceFill(['bundle_status' => null, 'bundle_disk' => null, 'bundle_path' => null, 'bundle_expires_at' => null])->saveQuietly();
            });
    }

    /**
     * Delete the temporary objects (password-protected downloads, pieces of uploads), which are older than a day. The
     * bucket's lifecycle does it too; a disk without one needs this.
     *
     * @return void
     */
    private function pruneTemporary(): void
    {
        $cutoff = now()->subHours((int) config('data-lifecycle.backups.temporary_hours'))->getTimestamp();

        foreach ($this->store->temporaryObjects() as $path => $modifiedAt) {
            if ($modifiedAt < $cutoff) {
                $this->store->delete($this->store->diskName(), $path);
            }
        }
    }
}
