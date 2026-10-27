<?php

namespace PryTax\DataLifecycle\Backup\Jobs;

use Core\Jobs\AsyncJob;
use PryTax\DataLifecycle\Backup\Internal\BundleBuilder;
use PryTax\DataLifecycle\Backup\Models\BackupRun;
use Throwable;

/**
 * Class BuildOrganizationBundle.
 *
 * Builds the organization's bundle of a run, which a user asked for (backups.md §4.5).
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Backup\Jobs
 */
class BuildOrganizationBundle extends AsyncJob
{
    /**
     * Seconds, after which an attempt is stopped.
     *
     * @var int
     */
    public int $timeout = 1800;

    /**
     * Determines if the job fails, unless an organization is current.
     *
     * @var bool
     */
    protected bool $requiresOrganization = true;

    /**
     * BuildOrganizationBundle constructor.
     *
     * @param string $runId
     */
    public function __construct(public string $runId)
    {
        $this->onQueue(config('data-lifecycle.backups.queue'));
    }

    /**
     * Build the bundle.
     *
     * @param BundleBuilder $builder
     *
     * @return void
     */
    public function handle(BundleBuilder $builder): void
    {
        $builder->build(BackupRun::query()->findOrFail($this->runId));
    }

    /**
     * Mark the bundle failed, once the job gives up.
     *
     * @param Throwable|null $exception
     *
     * @return void
     */
    public function failed(?Throwable $exception): void
    {
        BackupRun::query()->withoutGlobalScopes()->whereKey($this->runId)->update(['bundle_status' => 'failed']);
    }
}
