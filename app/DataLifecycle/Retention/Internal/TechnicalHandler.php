<?php

namespace PryTax\DataLifecycle\Retention\Internal;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use PryTax\DataLifecycle\Backup\Internal\ArchiveStore;
use PryTax\DataLifecycle\Contracts\RetentionFamilyHandler;
use PryTax\DataLifecycle\Contracts\TableRegistry;
use PryTax\DataLifecycle\Registry\MorphAliases;
use PryTax\DataLifecycle\Registry\SchemaInspector;
use PryTax\DataLifecycle\Retention\Models\RetentionJob;
use PryTax\DataLifecycle\Retention\RetentionResult;

/**
 * Class TechnicalHandler.
 *
 * The engine's rolling cleanup (backups.md §9.2 `technical`): rows of the registry's morph tables (settings,
 * sequences, files), whose owner no longer exists, after a week, the files' stored objects too; runs, which have no
 * backups left, after 90 days. Rolling cutoffs of a model of their own are Laravel's pruning (`model:prune`).
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Retention\Internal
 */
final class TechnicalHandler implements RetentionFamilyHandler
{
    /**
     * TechnicalHandler constructor.
     *
     * @param TableRegistry $registry
     * @param ArchiveStore $store
     */
    public function __construct(
        private readonly TableRegistry $registry,
        private readonly ArchiveStore $store,
    ) {}

    /**
     * Get the families, which the handler does.
     *
     * @return array<int, string>
     */
    public function families(): array
    {
        return ['technical'];
    }

    /**
     * Delete the orphaned morph rows and the empty runs.
     *
     * @param RetentionJob $job
     *
     * @return RetentionResult
     */
    public function handle(RetentionJob $job): RetentionResult
    {
        $result = new RetentionResult;
        $schema = new SchemaInspector(DB::connection());
        $cutoff = now()->subDays((int) config('data-lifecycle.retention.orphan_days'));

        foreach ($this->registry->tables() as $table) {
            if (!$table->isMorph()) {
                continue;
            }

            $morph = $table->morphName();

            foreach (array_keys(Relation::morphMap()) as $alias) {
                $owner = MorphAliases::tableOf($alias);

                // A morph table never owns its own rows (and MySQL can't delete from a table, which its subquery reads)
                if ($owner === null || $owner === $table->table() || !$schema->hasTable($owner)) {
                    continue;
                }

                $orphans = DB::table($table->table())
                    ->where("{$morph}_type", $alias)
                    ->whereNotExists(fn ($query) => $query->from($owner)->whereColumn("$owner.id", "{$table->table()}.{$morph}_id"))
                    ->when($schema->hasColumn($table->table(), 'created_at'), fn ($query) => $query->where('created_at', '<', $cutoff));

                if ($table->documentColumns() !== null) {
                    ['disk' => $disk, 'path' => $path] = $table->documentColumns();

                    foreach ((clone $orphans)->get([$disk, $path]) as $row) {
                        $this->store->delete((string) $row->$disk, (string) $row->$path);
                        $result->objectsDeleted++;
                    }
                }

                $result->deleted += $orphans->delete();
            }
        }

        $result->deleted += DB::table('backup_runs')
            ->where('created_at', '<', now()->subDays((int) config('data-lifecycle.retention.empty_run_days')))
            ->whereNotExists(fn ($query) => $query->from('backups')->whereColumn('backups.run_id', 'backup_runs.id'))
            ->delete();

        $result->processed = $result->deleted;

        return $result;
    }
}
