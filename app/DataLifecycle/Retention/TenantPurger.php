<?php

namespace PryTax\DataLifecycle\Retention;

use Illuminate\Support\Facades\DB;
use PryTax\DataLifecycle\Backup\Internal\ArchiveStore;
use PryTax\DataLifecycle\Backup\Internal\Selection;
use PryTax\DataLifecycle\Backup\Models\Backup;
use PryTax\DataLifecycle\Backup\Models\BackupDownload;
use PryTax\DataLifecycle\Backup\RunSpec;
use PryTax\DataLifecycle\Contracts\TableRegistry;
use PryTax\DataLifecycle\Contracts\TenantRoot;
use PryTax\DataLifecycle\Registry\SchemaInspector;

/**
 * Class TenantPurger.
 *
 * Deletes a tenant for good (backups.md §9.5), through the registry, which exports it, in reverse: children before
 * parents, morph rows before their owners, after the deferred columns are nulled, which breaks the cycles. Global
 * reference rows stay. It works with the query builder, soft-deleted rows included, so no observer (a locked
 * invoice's) and no audit entry takes part. The stored objects of the tenant's documents are listed first and deleted
 * after the rows' deletion is committed; the archives of its backups and the tenant's own row go last.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Retention
 */
final class TenantPurger
{
    /**
     * TenantPurger constructor.
     *
     * @param TableRegistry $registry
     * @param TenantRoot $root
     * @param ArchiveStore $store
     */
    public function __construct(
        private readonly TableRegistry $registry,
        private readonly TenantRoot $root,
        private readonly ArchiveStore $store,
    ) {}

    /**
     * Get the stored objects of the tenant's documents: disk and path of each.
     *
     * @param int $tenantId
     *
     * @return array<int, array{0: string, 1: string}>
     */
    public function documentsOf(int $tenantId): array
    {
        $selection = $this->selection($tenantId);
        $objects = [];

        foreach ($this->registry->tables() as $table) {
            if ($table->documentColumns() === null) {
                continue;
            }

            ['disk' => $disk, 'path' => $path] = $table->documentColumns();

            foreach ($selection->query($table)->get([$disk, $path]) as $row) {
                $objects[] = [(string) $row->$disk, (string) $row->$path];
            }
        }

        return $objects;
    }

    /**
     * Delete the tenant's rows of every table, but its own row and the reference rows, in one transaction.
     *
     * @param int $tenantId
     *
     * @return int Rows deleted.
     */
    public function purgeData(int $tenantId): int
    {
        $db = DB::connection();
        $selection = $this->selection($tenantId);
        $tables = $this->registry->tables();

        return $db->transaction(function () use ($db, $selection, $tables) {
            $db->statement('SET SESSION innodb_lock_wait_timeout = 30');

            foreach ($tables as $table) {
                $columns = $table->deferredColumns();

                if ($columns !== []) {
                    $selection->query($table)->update(array_fill_keys($columns, null));
                }
            }

            $deleted = 0;

            foreach (array_reverse($tables) as $table) {
                if ($table->isReference() || $table->isRoot()) {
                    continue;
                }

                $deleted += $selection->query($table)->delete();
            }

            return $deleted;
        });
    }

    /**
     * Delete stored objects; a missing one counts as deleted.
     *
     * @param array<int, array{0: string, 1: string}> $objects
     *
     * @return int
     */
    public function deleteObjects(array $objects): int
    {
        foreach ($objects as [$disk, $path]) {
            $this->store->delete($disk, $path);
        }

        return count($objects);
    }

    /**
     * Delete the tenant's backups: their archives, their records and their downloads.
     *
     * @param int $tenantId
     *
     * @return array{0: int, 1: int} Records deleted, and archives.
     */
    public function deleteBackups(int $tenantId): array
    {
        $backups = Backup::query()->withoutGlobalScopes()->where('profile_id', $tenantId)->get();
        $objects = 0;

        foreach ($backups as $backup) {
            if ($backup->disk !== null && $backup->object_path !== null) {
                $this->store->delete($backup->disk, $backup->object_path);
                $objects++;
            }
        }

        $ids = $backups->pluck('id')->all();
        $deleted = BackupDownload::query()->withoutGlobalScopes()->whereIn('backup_id', $ids)->delete();
        $deleted += Backup::query()->withoutGlobalScopes()->whereIn('id', $ids)->delete();

        return [$deleted, $objects];
    }

    /**
     * Delete the tenant's own row, once its data is gone.
     *
     * @param int $tenantId
     *
     * @return int
     */
    public function deleteRoot(int $tenantId): int
    {
        return DB::table($this->root->table())->where('id', $tenantId)->delete();
    }

    /**
     * Get the selection of the tenant, with every optional table, so a purge leaves none out.
     *
     * @param int $tenantId
     *
     * @return Selection
     */
    private function selection(int $tenantId): Selection
    {
        $options = [RunSpec::INCLUDE_DOCUMENTS => true];

        foreach ($this->registry->tables() as $table) {
            if ($table->optionalOn() !== null) {
                $options[$table->optionalOn()] = true;
            }
        }

        return new Selection(DB::connection(), $this->registry, $this->root, new SchemaInspector(DB::connection()), $tenantId, new RunSpec('purge', $options));
    }
}
