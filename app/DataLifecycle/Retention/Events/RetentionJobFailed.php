<?php

namespace PryTax\DataLifecycle\Retention\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Class RetentionJobFailed.
 *
 * A retention job failed, so its run is failed until ops put the job back (`prytax:retention:retry`).
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Retention\Events
 */
class RetentionJobFailed
{
    use Dispatchable;

    /**
     * RetentionJobFailed constructor.
     *
     * @param string $jobId
     * @param string $error
     */
    public function __construct(
        public readonly string $jobId,
        public readonly string $error,
    ) {}
}
