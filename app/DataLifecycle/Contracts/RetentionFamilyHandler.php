<?php

namespace PryTax\DataLifecycle\Contracts;

use PryTax\DataLifecycle\Retention\Models\RetentionJob;
use PryTax\DataLifecycle\Retention\RetentionResult;

/**
 * Interface RetentionFamilyHandler.
 *
 * Deletes or anonymizes the data of retention families (backups.md §9.2, §10.4): the app's handlers know its
 * subjects (a profile's data, a user's credentials), the engine's own the rolling technical cleanup. A handler is
 * idempotent: a failed job is run again from the start. It never swallows an exception.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Contracts
 */
interface RetentionFamilyHandler
{
    /**
     * Tag, under which the handlers are registered in the container.
     *
     * @var string
     */
    public const string TAG = 'data-lifecycle.retention-handlers';

    /**
     * Get the families, which the handler does.
     *
     * @return array<int, string>
     */
    public function families(): array;

    /**
     * Do the job's family for the job's run.
     *
     * @param RetentionJob $job
     *
     * @return RetentionResult
     */
    public function handle(RetentionJob $job): RetentionResult;
}
