<?php

namespace PryTax\Backups;

use Core\Models\AuditLog;
use PryTax\DataLifecycle\Contracts\TableRegistry;
use PryTax\DataLifecycle\Registry\MorphAliases;
use PryTax\DataLifecycle\Registry\TableSpec;
use PryTax\Helpers\CurrentProfile;

/**
 * Class ProfileAuditTagger.
 *
 * Tags every audit entry of profile data with `metadata.profile_id` as it is created (backups.md §4.2 "Audit
 * entries"): entries can't be changed afterwards, and the subject of a deletion is gone, so the archive and the purge
 * of a profile select its history by this key, not by subject. The decision uses the entry's columns only, never its
 * `subject` relation: a profile's own entries carry its id; an entry of a record of a table of profile data (its
 * records, their children, the api-core rows they own) carries the current profile; anything else, such as a change
 * of the user or of the organization during a profile's request, stays untagged and belongs to the organization.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Backups
 */
final class ProfileAuditTagger
{
    /**
     * Tables of profile data, but the profile itself.
     *
     * @var array<int, string>|null
     */
    private ?array $tables = null;

    /**
     * ProfileAuditTagger constructor.
     *
     * @param TableRegistry $registry
     */
    public function __construct(private readonly TableRegistry $registry) {}

    /**
     * Tag the entry, which is being created, keeping the metadata it has.
     *
     * @param AuditLog $entry
     *
     * @return void
     */
    public function tag(AuditLog $entry): void
    {
        $profileId = $this->resolve($entry);

        if ($profileId !== null) {
            $entry->metadata = [...($entry->metadata ?? []), 'profile_id' => $profileId];
        }
    }

    /**
     * Get the profile, whose data the entry is of, or null, when it isn't of profile data.
     *
     * @param AuditLog $entry
     *
     * @return int|null
     */
    public function resolve(AuditLog $entry): ?int
    {
        $table = $entry->subject_type === null ? null : MorphAliases::tableOf($entry->subject_type);

        if ($table === null) {
            return null;
        }

        // Profile creation and its settings, where no profile is current yet
        if ($table === 'profiles') {
            return $entry->subject_id === null ? null : (int) $entry->subject_id;
        }

        return in_array($table, $this->tables(), true) ? CurrentProfile::id() : null;
    }

    /**
     * Get the tables of profile data: the registry's, but the reference ones, the profile and the audit log.
     *
     * @return array<int, string>
     */
    private function tables(): array
    {
        return $this->tables ??= array_values(array_map(
            fn (TableSpec $table) => $table->table(),
            array_filter(
                $this->registry->tables(),
                fn (TableSpec $table) => !$table->isReference() && !$table->isRoot() && $table->strategy() !== TableSpec::PROFILE_JSON
            )
        ));
    }
}
