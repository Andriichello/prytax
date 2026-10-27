<?php

namespace PryTax\DataLifecycle\Backup\Jobs;

use Core\Helpers\CurrentOrganization;
use Core\Jobs\AsyncJob;
use DateTimeInterface;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use PryTax\DataLifecycle\Backup\Internal\BackupRunner;
use PryTax\DataLifecycle\Backup\Models\BackupRun;
use Throwable;

/**
 * Class RunOrganizationBackup.
 *
 * Executes a run of an organization (backups.md §4.3), dispatched for it with `dispatchFor()`, since the scheduler
 * has no current organization. Runs of one organization never overlap: a second one is released back onto the queue,
 * not dropped (a manual run, or the safety backup of a deleted profile, must happen), so a release isn't counted as an
 * attempt; three failures give up, after waits of 5, 15 and 45 minutes. A retry resumes the run.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Backup\Jobs
 */
class RunOrganizationBackup extends AsyncJob
{
    /**
     * The number of times the job may be attempted: releases while another run is busy don't count.
     *
     * @var int
     */
    public int $tries = 0;

    /**
     * The number of failures, after which the job gives up.
     *
     * @var int
     */
    public int $maxExceptions = 3;

    /**
     * Seconds, after which an attempt is stopped.
     *
     * @var int
     */
    public int $timeout = 900;

    /**
     * Determines if the job fails, unless an organization is current.
     *
     * @var bool
     */
    protected bool $requiresOrganization = true;

    /**
     * Id of the organization, whose runs don't overlap.
     *
     * @var int
     */
    public int $organizationId;

    /**
     * RunOrganizationBackup constructor.
     *
     * @param string $runId
     */
    public function __construct(public string $runId)
    {
        $this->organizationId = (int) CurrentOrganization::id();
        $this->onQueue(config('data-lifecycle.backups.queue'));
    }

    /**
     * Execute the run.
     *
     * @param BackupRunner $runner
     *
     * @return void
     */
    public function handle(BackupRunner $runner): void
    {
        $runner->run(BackupRun::query()->findOrFail($this->runId));
    }

    /**
     * Mark the run failed, once the job gives up.
     *
     * @param Throwable|null $exception
     *
     * @return void
     */
    public function failed(?Throwable $exception): void
    {
        $run = BackupRun::query()->withoutGlobalScopes()->find($this->runId);

        if ($run !== null && !$run->status->isFinished()) {
            app(BackupRunner::class)->fail($run, $exception);
        }
    }

    /**
     * Get the seconds to wait before each retry.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [300, 900, 2700];
    }

    /**
     * Get the moment, until which the job is retried, while it waits for another run.
     *
     * @return DateTimeInterface
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(12);
    }

    /**
     * Get the middleware the job should pass through.
     *
     * @return array<int, object|callable>
     */
    public function middleware(): array
    {
        return [...parent::middleware(), (new WithoutOverlapping("backups:$this->organizationId"))->releaseAfter(60)->expireAfter(1200)];
    }
}
