<?php

namespace PryTax\Retention;

use Illuminate\Support\Facades\DB;
use LogicException;
use PryTax\DataLifecycle\Contracts\RetentionFamilyHandler;
use PryTax\DataLifecycle\Retention\Models\RetentionJob;
use PryTax\DataLifecycle\Retention\RetentionResult;
use PryTax\DataLifecycle\Retention\TenantPurger;

/**
 * Class AccountingFamilyHandler.
 *
 * Purges a deleted ФОП's data once its grace is over (backups.md §9.2 `accounting`, §9.5): every table of the
 * registry but the profile's own row, its notifications, settings, counters, files and audit history included,
 * through the registry in reverse. The stored objects of its files are listed first, for the `documents` job, which
 * deletes them after this commit. A profile, which isn't deleted (restored during its grace), is never purged.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Retention
 */
class AccountingFamilyHandler implements RetentionFamilyHandler
{
    /**
     * AccountingFamilyHandler constructor.
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
        return ['accounting'];
    }

    /**
     * Purge the profile's data.
     *
     * @param RetentionJob $job
     *
     * @return RetentionResult
     */
    public function handle(RetentionJob $job): RetentionResult
    {
        $profileId = (int) $job->run->subject_id;
        $profile = DB::table('profiles')->where('id', $profileId)->first(['id', 'deleted_at']);

        if ($profile !== null && $profile->deleted_at === null) {
            throw new LogicException("The profile $profileId isn't deleted; it is never purged.");
        }

        $documents = $job->run->job('documents');
        $documents?->forceFill(['payload' => ['objects' => $this->purger->documentsOf($profileId)]])->save();

        $deleted = $profile === null ? 0 : $this->purger->purgeData($profileId);

        return new RetentionResult(processed: $deleted, deleted: $deleted);
    }
}
