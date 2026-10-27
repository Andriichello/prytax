<?php

namespace PryTax\Retention;

use PryTax\DataLifecycle\Contracts\RetentionFamilyHandler;
use PryTax\DataLifecycle\Retention\Models\RetentionJob;
use PryTax\DataLifecycle\Retention\RetentionResult;
use PryTax\DataLifecycle\Retention\TenantPurger;

/**
 * Class DocumentsFamilyHandler.
 *
 * Deletes the stored objects of a purged ФОП's files (backups.md §9.2 `documents`): the list, which the `accounting`
 * job left before its commit, never a listing by prefix across tenants. A missing object counts as deleted, so a
 * repeated job does no harm.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Retention
 */
class DocumentsFamilyHandler implements RetentionFamilyHandler
{
    /**
     * DocumentsFamilyHandler constructor.
     *
     * @param TenantPurger $purger
     */
    public function __construct(protected TenantPurger $purger) {}

    /**
     * Get the families, which the handler does.
     *
     * @return array<int, string>
     */
    public function families(): array
    {
        return ['documents'];
    }

    /**
     * Delete the objects.
     *
     * @param RetentionJob $job
     *
     * @return RetentionResult
     */
    public function handle(RetentionJob $job): RetentionResult
    {
        $objects = $job->payload['objects'] ?? [];
        $deleted = $this->purger->deleteObjects($objects);

        return new RetentionResult(processed: count($objects), objectsDeleted: $deleted);
    }
}
