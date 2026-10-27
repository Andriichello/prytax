<?php

namespace PryTax\DataLifecycle\Backup\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Class BackupRunFinished.
 *
 * A run is over: completed, partial or failed. The app tells the organization's owners by its policy.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Backup\Events
 */
class BackupRunFinished
{
    use Dispatchable;

    /**
     * BackupRunFinished constructor.
     *
     * @param string $runId
     * @param int $organizationId
     */
    public function __construct(
        public readonly string $runId,
        public readonly int $organizationId,
    ) {}
}
