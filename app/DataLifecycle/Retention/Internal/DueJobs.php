<?php

namespace PryTax\DataLifecycle\Retention\Internal;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use PryTax\DataLifecycle\Retention\Enums\RetentionStatus;
use PryTax\DataLifecycle\Retention\Jobs\RunRetentionJob;
use PryTax\DataLifecycle\Retention\Models\RetentionJob;

/**
 * Class DueJobs.
 *
 * Dispatches the retention jobs, which are due (backups.md §9.5): pending, past `due_at`, and with every earlier job
 * of their run completed. Each is claimed atomically first (`UPDATE … WHERE status = 'pending'`), so of two
 * schedulers or workers only one runs it.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Retention\Internal
 */
final class DueJobs
{
    /**
     * Dispatch the due jobs, and the ones, which their completion makes due.
     *
     * @return int Jobs dispatched.
     */
    public function dispatch(): int
    {
        $dispatched = 0;

        // A job, which runs at once (the sync queue), makes the next one of its run due in the next round
        for ($round = 0; $round < 50; $round++) {
            $claimed = 0;

            foreach ($this->due() as $id) {
                if ($this->claim($id)) {
                    RunRetentionJob::dispatch($id);
                    $claimed++;
                }
            }

            $dispatched += $claimed;

            if ($claimed === 0) {
                break;
            }
        }

        return $dispatched;
    }

    /**
     * Claim the job, if it is still pending.
     *
     * @param string $id
     *
     * @return bool Whether this process owns it now.
     */
    public function claim(string $id): bool
    {
        return RetentionJob::query()->whereKey($id)->where('status', RetentionStatus::Pending)->update([
            'status' => RetentionStatus::Dispatched,
            'started_at' => now(),
            'attempts' => DB::raw('attempts + 1'),
        ]) === 1;
    }

    /**
     * Get ids of the jobs, which are due.
     *
     * @return array<int, string>
     */
    private function due(): array
    {
        return RetentionJob::query()
            ->where('status', RetentionStatus::Pending)
            ->where('due_at', '<=', now())
            ->whereNotExists(fn (Builder $query) => $query
                ->from('data_retention_jobs as earlier')
                ->whereColumn('earlier.run_id', 'data_retention_jobs.run_id')
                ->whereColumn('earlier.sequence', '<', 'data_retention_jobs.sequence')
                ->where('earlier.status', '!=', RetentionStatus::Completed->value))
            ->orderBy('due_at')
            ->orderBy('sequence')
            ->pluck('id')
            ->all();
    }
}
