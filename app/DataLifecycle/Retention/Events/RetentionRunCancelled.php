<?php

namespace PryTax\DataLifecycle\Retention\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Class RetentionRunCancelled.
 *
 * A deletion run was cancelled during its grace, so the app reverses the lock of its subject.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Retention\Events
 */
class RetentionRunCancelled
{
    use Dispatchable;

    /**
     * RetentionRunCancelled constructor.
     *
     * @param string $runId
     */
    public function __construct(public readonly string $runId) {}
}
