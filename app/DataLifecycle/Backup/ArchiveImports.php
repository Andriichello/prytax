<?php

namespace PryTax\DataLifecycle\Backup;

use Illuminate\Support\Facades\DB;
use PryTax\DataLifecycle\Backup\Internal\ArchiveImporter;
use Throwable;

/**
 * Class ArchiveImports.
 *
 * The importer, as the app uses it (backups.md §5.1, §10.3): what an archive holds (a tenant's archive, or every one
 * of a run's bundle), importing all its tenants into an organization as new tenants, and removing the documents of an
 * import again, when the caller's own transaction rolled its rows back.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Backup
 */
final class ArchiveImports
{
    /**
     * ArchiveImports constructor.
     *
     * @param ArchiveImporter $importer
     */
    public function __construct(private readonly ArchiveImporter $importer) {}

    /**
     * Tell what the archive holds, without importing it: each tenant's archive in it, under its folder.
     *
     * @param string $path Local path of the archive.
     * @param string|null $password The password of an archive downloaded with one.
     *
     * @return list<array{folder: string, tenant: array<string, mixed>, organization: array<string, mixed>|null, snapshot_at: string|null, rows: int, documents: int}>
     * @throws ImportException With `PASSWORD_REQUIRED` as its code, when it has a password and none was given.
     */
    public function inspect(string $path, ?string $password = null): array
    {
        return $this->importer->inspect($path, $password);
    }

    /**
     * Import every tenant of the archive (one, or each of a run's bundle) into the organization as new tenants, in
     * one transaction, which joins the caller's: all of them or, when one can't be imported, none, with its documents
     * removed again. A dry run imports and verifies each, and writes nothing.
     *
     * @param string $path Local path of the archive.
     * @param int $organizationId
     * @param bool $dryRun
     * @param string|null $password The password of an archive downloaded with one.
     *
     * @return list<ImportResult>
     * @throws ImportException
     */
    public function importAll(string $path, int $organizationId, bool $dryRun = false, ?string $password = null): array
    {
        $folders = array_column($this->importer->inspect($path, $password), 'folder');

        if ($folders === []) {
            throw new ImportException('The archive holds no profile.');
        }

        if ($dryRun) {
            return array_map(fn (string $folder) => $this->importer->import($path, $organizationId, true, $password, $folder), $folders);
        }

        $results = [];

        try {
            DB::transaction(function () use ($folders, $path, $organizationId, $password, &$results) {
                foreach ($folders as $folder) {
                    $results[] = $this->importer->import($path, $organizationId, false, $password, $folder);
                }
            });
        } catch (Throwable $exception) {
            array_map(fn (ImportResult $result) => $this->importer->discard($result), $results);

            throw $exception;
        }

        return $results;
    }

    /**
     * Remove the documents of an import, whose rows the caller's transaction rolled back.
     *
     * @param ImportResult $result
     *
     * @return void
     */
    public function discard(ImportResult $result): void
    {
        $this->importer->discard($result);
    }
}
