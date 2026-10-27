<?php

namespace PryTax\DataLifecycle\Retention;

use Illuminate\Support\Facades\DB;
use LogicException;
use PryTax\DataLifecycle\Retention\Enums\RetentionStatus;
use PryTax\DataLifecycle\Retention\Events\RetentionRunCancelled;
use PryTax\DataLifecycle\Retention\Models\RetentionJob;
use PryTax\DataLifecycle\Retention\Models\RetentionRun;

/**
 * Class RetentionRuns.
 *
 * Plans the deletion runs (backups.md §9.3, §9.4): a run of a subject with a job per family, in their order, due
 * now or when the grace period ends; one active run per subject. Cancelling one during its grace cancels its
 * pending jobs, and the app reverses the lock.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Retention
 */
class RetentionRuns
{
    /**
     * Plan a run.
     *
     * @param string $subjectType
     * @param int|null $subjectId
     * @param int|null $organizationId
     * @param string $trigger
     * @param array<int, string> $families In their order.
     * @param int $graceDays Days, after which its jobs are due; 0 for now.
     * @param int|null $userId
     * @param array<string, string> $actions Families, which anonymize rather than delete.
     *
     * @return RetentionRun
     * @throws LogicException When the subject has an active run.
     */
    public function plan(
        string $subjectType,
        ?int $subjectId,
        ?int $organizationId,
        string $trigger,
        array $families,
        int $graceDays,
        ?int $userId = null,
        array $actions = [],
    ): RetentionRun {
        return DB::transaction(function () use ($subjectType, $subjectId, $organizationId, $trigger, $families, $graceDays, $userId, $actions) {
            if ($subjectId !== null && $this->activeOf($subjectType, $subjectId, lock: true) !== null) {
                throw new LogicException("The $subjectType $subjectId has an active deletion run already.");
            }

            $run = new RetentionRun;
            $run->forceFill([
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'organization_id' => $organizationId,
                'trigger' => $trigger,
                'immediate' => $graceDays === 0,
                'requested_by_user_id' => $userId,
            ])->save();

            $dueAt = now()->addDays($graceDays);

            foreach (array_values($families) as $sequence => $family) {
                $job = new RetentionJob;
                $job->forceFill([
                    'run_id' => $run->id,
                    'family' => $family,
                    'action' => $actions[$family] ?? 'hard_delete',
                    'sequence' => $sequence,
                    'due_at' => $dueAt,
                ])->save();
            }

            return $run;
        });
    }

    /**
     * Get the active (pending or running) run of the subject.
     *
     * @param string $subjectType
     * @param int $subjectId
     * @param bool $lock
     *
     * @return RetentionRun|null
     */
    public function activeOf(string $subjectType, int $subjectId, bool $lock = false): ?RetentionRun
    {
        return RetentionRun::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->whereIn('status', [RetentionStatus::Pending, RetentionStatus::Running])
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->first();
    }

    /**
     * Cancel the run during its grace: its pending jobs; one, which has started, can't be.
     *
     * @param RetentionRun $run
     *
     * @return void
     * @throws LogicException When a job of the run has started already.
     */
    public function cancel(RetentionRun $run): void
    {
        DB::transaction(function () use ($run) {
            $started = $run->jobs()->whereNotIn('status', [RetentionStatus::Pending, RetentionStatus::Cancelled])->exists();

            if ($started) {
                throw new LogicException("The deletion run $run->id has started; it can't be cancelled.");
            }

            $run->jobs()->update(['status' => RetentionStatus::Cancelled]);
            $run->forceFill(['status' => RetentionStatus::Cancelled, 'cancelled_at' => now()])->save();
        });

        RetentionRunCancelled::dispatch($run->id);
    }

    /**
     * Cancel the active run of the subject, when it has one.
     *
     * @param string $subjectType
     * @param int $subjectId
     *
     * @return bool Whether a run was cancelled.
     */
    public function cancelOf(string $subjectType, int $subjectId): bool
    {
        $run = $this->activeOf($subjectType, $subjectId);

        if ($run === null) {
            return false;
        }

        $this->cancel($run);

        return true;
    }
}
