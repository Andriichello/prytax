<?php

namespace PryTax\DataLifecycle\Retention\Jobs;

use Core\Jobs\AsyncJob;
use Illuminate\Support\Str;
use PryTax\DataLifecycle\Retention\Enums\RetentionStatus;
use PryTax\DataLifecycle\Retention\Events\RetentionJobFailed;
use PryTax\DataLifecycle\Retention\Internal\RetentionHandlers;
use PryTax\DataLifecycle\Retention\Models\RetentionJob;
use Throwable;

/**
 * Class RunRetentionJob.
 *
 * Runs a claimed retention job through its family's handler (backups.md §9.5), and records what it did. A failure is
 * recorded on the job, leaves its run failed and is reported; it isn't retried by the queue: ops put the job back
 * with `prytax:retention:retry`, as handlers are idempotent.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Retention\Jobs
 */
class RunRetentionJob extends AsyncJob
{
    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public int $tries = 1;

    /**
     * Seconds, after which the attempt is stopped.
     *
     * @var int
     */
    public int $timeout = 1800;

    /**
     * RunRetentionJob constructor.
     *
     * @param string $jobId
     */
    public function __construct(public string $jobId)
    {
        $this->onQueue(config('data-lifecycle.retention.queue'));
    }

    /**
     * Run the job through its handler.
     *
     * @param RetentionHandlers $handlers
     *
     * @return void
     */
    public function handle(RetentionHandlers $handlers): void
    {
        $job = RetentionJob::query()->find($this->jobId);

        if ($job === null || $job->status !== RetentionStatus::Dispatched) {
            return;
        }

        try {
            $result = $handlers->for($job->family)->handle($job);

            $job->forceFill([
                'status' => RetentionStatus::Completed,
                'records_processed' => $result->processed,
                'records_deleted' => $result->deleted,
                'records_anonymized' => $result->anonymized,
                'objects_deleted' => $result->objectsDeleted,
                'error' => null,
                'finished_at' => now(),
            ])->save();
        } catch (Throwable $exception) {
            report($exception);

            $job->forceFill([
                'status' => RetentionStatus::Failed,
                'error' => Str::limit($exception->getMessage(), 2000),
                'finished_at' => now(),
            ])->save();

            RetentionJobFailed::dispatch($job->id, $exception->getMessage());
        }

        $job->run->refreshStatus();
    }
}
