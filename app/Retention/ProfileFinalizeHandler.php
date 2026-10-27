<?php

namespace PryTax\Retention;

use PryTax\DataLifecycle\Contracts\RetentionFamilyHandler;
use PryTax\DataLifecycle\Retention\Models\RetentionJob;
use PryTax\DataLifecycle\Retention\RetentionResult;
use PryTax\DataLifecycle\Retention\TenantPurger;

/**
 * Class ProfileFinalizeHandler.
 *
 * The last job of a ФОП's purge (backups.md §9.2 `backups`, §9.3): its archives, their records and downloads, then
 * the profile's own row, which `backups.profile_id` held until then. So the final export stays downloadable for the
 * whole grace period.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Retention
 */
class ProfileFinalizeHandler implements RetentionFamilyHandler
{
    /**
     * ProfileFinalizeHandler constructor.
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
        return ['profile_finalize'];
    }

    /**
     * Delete the profile's backups and its row.
     *
     * @param RetentionJob $job
     *
     * @return RetentionResult
     */
    public function handle(RetentionJob $job): RetentionResult
    {
        $profileId = (int) $job->run->subject_id;
        [$deleted, $objects] = $this->purger->deleteBackups($profileId);
        $deleted += $this->purger->deleteRoot($profileId);

        return new RetentionResult(processed: $deleted, deleted: $deleted, objectsDeleted: $objects);
    }
}
