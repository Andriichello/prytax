<?php

namespace PryTax\Backups;

use Core\Models\AuditLog;
use Core\Models\User;
use PryTax\DataLifecycle\Backup\ArchiveImports;
use PryTax\DataLifecycle\Backup\ImportException;
use PryTax\DataLifecycle\Backup\ImportResult;
use PryTax\DataLifecycle\Backup\Models\Backup;
use PryTax\Exceptions\ArchiveRefusedException;
use Throwable;

/**
 * Class ProfileArchives.
 *
 * Backup archives of ФОПs, as a new organization starts from them (`prytax:create-owner --from`, builds.md §1.3): a
 * ФОП's archive, or a backup's bundle of every ФОП it had, as downloaded. It tells the ФОПs of each, and imports all of
 * them into the organization as new ФОПs, or none.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Backups
 */
class ProfileArchives
{
    /**
     * ProfileArchives constructor.
     *
     * @param ArchiveImports $imports
     */
    public function __construct(protected ArchiveImports $imports) {}

    /**
     * Tell the ФОПs of an archive, as their manifests describe them, with what an import of each would write.
     *
     * @param string $path
     * @param string|null $password The password of an archive downloaded with one.
     *
     * @return list<array{folder: string, full_name: string|null, tax_id_last4: string|null, organization: string|null, snapshot_at: string|null, rows: int, documents: int}>
     * @throws ImportException With `PASSWORD_REQUIRED` as its code, when it has a password and none was given.
     */
    public function inspect(string $path, ?string $password = null): array
    {
        $profiles = array_map(fn (array $tenant) => [
            'folder' => $tenant['folder'],
            'full_name' => $tenant['tenant']['full_name'] ?? null,
            'tax_id_last4' => $tenant['tenant']['tax_id_last4'] ?? null,
            'organization' => $tenant['organization']['name'] ?? null,
            'snapshot_at' => $tenant['snapshot_at'],
            'rows' => $tenant['rows'],
            'documents' => $tenant['documents'],
        ], $this->imports->inspect($path, $password));

        if ($profiles === []) {
            throw new ImportException('The archive holds no profile.');
        }

        return $profiles;
    }

    /**
     * Import every ФОП of the archives into the organization, within the caller's transaction, each recorded in the
     * audit log as the causer's. One, which can't be imported (or the same ФОП twice), refuses them all: the documents
     * stored already are removed again, and the caller's transaction rolls the rows back.
     *
     * @param list<array{path: string, password?: string|null, name?: string}> $archives
     * @param int $organizationId
     * @param User|null $causer
     *
     * @return list<ImportResult>
     * @throws ArchiveRefusedException
     */
    public function import(array $archives, int $organizationId, ?User $causer = null): array
    {
        [$results, $seen] = [[], []];

        try {
            foreach (array_values($archives) as $index => $archive) {
                try {
                    foreach ($this->inspect($archive['path'], $archive['password'] ?? null) as $profile) {
                        $key = $profile['full_name'] === null || $profile['tax_id_last4'] === null ? null : "{$profile['full_name']}|{$profile['tax_id_last4']}";

                        if ($key !== null && isset($seen[$key])) {
                            throw new ImportException(__('The archives hold the ФОП :name twice: leave one of them.', ['name' => $profile['full_name']]));
                        }

                        $seen[$key ?? count($seen)] = true;
                    }

                    foreach ($this->imports->importAll($archive['path'], $organizationId, false, $archive['password'] ?? null) as $result) {
                        $results[] = $result;

                        AuditLog::record('backup_imported', null, [
                            'name' => (new Backup)->getMorphClass() . '.imported',
                            'metadata' => ['tenant_id' => $result->tenantId, 'file_name' => $archive['name'] ?? basename($archive['path'])],
                            'causer' => $causer,
                        ]);
                    }
                } catch (ImportException $exception) {
                    throw new ArchiveRefusedException($index, $exception->getMessage(), $exception);
                }
            }
        } catch (Throwable $exception) {
            foreach ($results as $result) {
                $this->imports->discard($result);
            }

            throw $exception;
        }

        return $results;
    }
}
