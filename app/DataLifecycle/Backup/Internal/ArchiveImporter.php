<?php

namespace PryTax\DataLifecycle\Backup\Internal;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use JsonException;
use PryTax\DataLifecycle\Backup\Events\ArchiveImported;
use PryTax\DataLifecycle\Backup\ImportException;
use PryTax\DataLifecycle\Backup\ImportResult;
use PryTax\DataLifecycle\Backup\RunSpec;
use PryTax\DataLifecycle\Contracts\ArchiveChecks;
use PryTax\DataLifecycle\Contracts\ReferenceResolver;
use PryTax\DataLifecycle\Contracts\TableRegistry;
use PryTax\DataLifecycle\Contracts\TenantRoot;
use PryTax\DataLifecycle\Registry\MorphAliases;
use PryTax\DataLifecycle\Registry\Rewrite;
use PryTax\DataLifecycle\Registry\SchemaInspector;
use PryTax\DataLifecycle\Registry\TableSpec;
use stdClass;
use Throwable;
use ZipArchive;

/**
 * Class ArchiveImporter.
 *
 * Imports a tenant's archive into an organization as a new tenant, never in place (backups.md §5.1). Reference rows
 * are matched first, never inserted; then, in one transaction, every table in registry order with its ids remapped
 * (foreign keys, morph pairs, ids inside values), encrypted columns encrypted again with the current key, generated
 * columns skipped, deferred columns set last, and documents uploaded to the files disk. The rows of each table and
 * the archive's checks are verified before the commit: any difference rolls everything back. Export-only tables are
 * skipped. A dry run does all of it and rolls back. A tenant's archive inside a run's bundle is read from its folder;
 * `inspect()` tells what an archive holds without importing it.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Backup\Internal
 */
final class ArchiveImporter
{
    /**
     * The archive.
     *
     * @var ZipArchive
     */
    private ZipArchive $zip;

    /**
     * Ids of the archive mapped to the ids written, by table (null for a row matched to nothing, or left out).
     *
     * @var array<string, array<string, int|null>>
     */
    private array $maps = [];

    /**
     * Positions of the tables in the registry.
     *
     * @var array<string, int>
     */
    private array $positions = [];

    /**
     * Deferred columns to set after every table: table, new id, column, referenced table, old id.
     *
     * @var array<int, array{0: string, 1: int, 2: string, 3: string, 4: string}>
     */
    private array $deferred = [];

    /**
     * Rewrites, whose table comes later, to apply after every table: table, new id, rewrite, value.
     *
     * @var array<int, array{0: TableSpec, 1: int, 2: Rewrite, 3: string|null}>
     */
    private array $lateRewrites = [];

    /**
     * Documents uploaded, which a failure or a dry run deletes again.
     *
     * @var array<int, array{0: string, 1: string}>
     */
    private array $uploaded = [];

    /**
     * The folder of the tenant's archive within the zip: none, or a run's bundle's `profiles/<folder>/`.
     *
     * @var string
     */
    private string $folder = '';

    /**
     * ArchiveImporter constructor.
     *
     * @param TableRegistry $registry
     * @param TenantRoot $root
     * @param ArchiveChecks $checks
     * @param ReferenceResolver|null $references The app's way to reference rows this database lacks, if any.
     */
    public function __construct(
        private readonly TableRegistry $registry,
        private readonly TenantRoot $root,
        private readonly ArchiveChecks $checks,
        private readonly ?ReferenceResolver $references = null,
    ) {}

    /**
     * Import the archive into the organization as a new tenant.
     *
     * @param string $path Local path of the archive.
     * @param int $organizationId
     * @param bool $dryRun
     * @param string|null $password The password of an archive downloaded with one.
     * @param string $folder The tenant's folder in a run's bundle (`profiles/<folder>/`), as `inspect()` tells it.
     *
     * @return ImportResult
     * @throws ImportException
     */
    public function import(string $path, int $organizationId, bool $dryRun = false, ?string $password = null, string $folder = ''): ImportResult
    {
        $this->maps = $this->deferred = $this->lateRewrites = $this->uploaded = [];
        $this->positions = [];

        foreach ($this->registry->tables() as $position => $table) {
            $this->positions[$table->table()] = $position;
        }

        $this->open($path);
        $this->folder = $folder === '' ? '' : rtrim($folder, '/') . '/';

        try {
            $this->unlock($password);
            $manifest = $this->manifest();
            $data = $this->data($manifest);
            $rootRow = $data[$this->root->table()][0] ?? throw new ImportException('The archive has no tenant.');
            $conflict = $this->root->importConflict($rootRow, $organizationId);

            if ($conflict !== null) {
                throw new ImportException($conflict);
            }

            $db = DB::connection();
            $schema = new SchemaInspector($db);
            $this->matchReferences($data, $schema, $organizationId, $db);

            $result = null;

            try {
                $db->transaction(function () use ($data, $schema, $organizationId, $db, $manifest, $dryRun, &$result) {
                    $result = $this->write($data, $schema, $organizationId, $db, $manifest, $dryRun);

                    if ($dryRun) {
                        throw new DryRunRollback;
                    }
                });
            } catch (DryRunRollback) {
                // Rolled back, as asked
            }

            if ($dryRun) {
                $this->deleteUploaded();
            } else {
                ArchiveImported::dispatch($result->tenantId, $organizationId);
            }

            return $result;
        } catch (Throwable $exception) {
            $this->deleteUploaded();

            throw $exception;
        } finally {
            $this->folder = '';
            $this->zip->close();
        }
    }

    /**
     * Tell what the archive holds, without importing it: each tenant's archive in it (one, or every folder of a run's
     * bundle), as its manifest describes it, with the rows and documents an import would write. An archive, which
     * `import()` would refuse to read, is refused alike.
     *
     * @param string $path Local path of the archive.
     * @param string|null $password The password of an archive downloaded with one.
     *
     * @return list<array{folder: string, tenant: array<string, mixed>, organization: array<string, mixed>|null, snapshot_at: string|null, rows: int, documents: int}>
     * @throws ImportException
     */
    public function inspect(string $path, ?string $password = null): array
    {
        $this->open($path);
        $this->folder = '';

        try {
            $this->unlock($password);
            $json = $this->zip->getFromName('manifest.json');
            $root = $json === false ? null : json_decode($json, true);
            $folders = is_array($root) && ($root['format'] ?? null) === ArchiveWriter::FORMAT . '-bundle'
                ? array_map(fn (array $tenant) => rtrim((string) ($tenant['folder'] ?? ''), '/') . '/', $root['profiles'] ?? [])
                : [''];
            $tenants = [];

            foreach ($folders as $folder) {
                $this->folder = $folder;
                $manifest = $this->manifest();
                $tenants[] = [
                    'folder' => $folder,
                    'tenant' => $manifest[$this->root->manifestKey()] ?? [],
                    'organization' => $manifest['organization'] ?? null,
                    'snapshot_at' => $manifest['snapshot_at'] ?? null,
                    'rows' => $this->importedRows($manifest),
                    'documents' => (int) ($manifest['documents']['count'] ?? 0),
                ];
            }

            return $tenants;
        } finally {
            $this->folder = '';
            $this->zip->close();
        }
    }

    /**
     * Delete the documents, which an import stored, once the caller's own transaction rolled its rows back.
     *
     * @param ImportResult $result
     *
     * @return void
     */
    public function discard(ImportResult $result): void
    {
        foreach ($result->uploaded as [$disk, $path]) {
            Storage::disk($disk)->delete($path);
        }
    }

    /**
     * Open the archive.
     *
     * @param string $path
     *
     * @return void
     * @throws ImportException When it isn't a zip archive.
     */
    private function open(string $path): void
    {
        $this->zip = new ZipArchive;

        if ($this->zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new ImportException('The file isn\'t a zip archive.');
        }
    }

    /**
     * Get the name of an entry of the tenant's archive within the zip.
     *
     * @param string $name As the tenant's archive names it (`data/payments.csv`).
     *
     * @return string
     */
    private function entry(string $name): string
    {
        return $this->folder . $name;
    }

    /**
     * Get the rows, which an import of the manifest's archive writes: of its tables, which are neither references nor
     * export-only.
     *
     * @param array<string, mixed> $manifest
     *
     * @return int
     */
    private function importedRows(array $manifest): int
    {
        $listed = array_column($manifest['files'] ?? [], 'rows', 'path');
        $rows = 0;

        foreach ($this->registry->tables() as $table) {
            if (!$table->isReference() && !$table->isExportOnly()) {
                $rows += (int) ($listed[$table->archivePath()] ?? 0);
            }
        }

        return $rows;
    }

    /**
     * Open an archive, which was downloaded with a password (its entries are encrypted), with the password.
     *
     * @param string|null $password
     *
     * @return void
     * @throws ImportException When it has one, but none or another was given.
     */
    private function unlock(?string $password): void
    {
        $encrypted = ($this->zip->statName($this->entry('manifest.json'))['encryption_method'] ?? ZipArchive::EM_NONE) !== ZipArchive::EM_NONE;

        if (!$encrypted) {
            return;
        }

        if ($password === null || $password === '') {
            throw new ImportException('The archive is protected by a password.', ImportException::PASSWORD_REQUIRED);
        }

        $this->zip->setPassword($password);

        if ($this->zip->getFromName($this->entry('manifest.json')) === false) {
            throw new ImportException('The password doesn\'t open the archive.');
        }
    }

    /**
     * Read and validate the manifest.
     *
     * @return array<string, mixed>
     */
    private function manifest(): array
    {
        $json = $this->zip->getFromName($this->entry('manifest.json'));

        try {
            $manifest = $json === false ? null : json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $manifest = null;
        }

        if (is_array($manifest) && ($manifest['format'] ?? null) === ArchiveWriter::FORMAT . '-bundle') {
            throw new ImportException('The archive holds every profile of a backup: download the backup of the one profile, and import that.');
        }

        if (!is_array($manifest) || ($manifest['format'] ?? null) !== ArchiveWriter::FORMAT) {
            throw new ImportException('The archive isn\'t a backup of this app.');
        }

        $version = (int) ($manifest['format_version'] ?? 0);

        if ($version > $this->registry->formatVersion()) {
            throw new ImportException("The archive is of format $version, newer than this app's {$this->registry->formatVersion()}.");
        }

        if ($version < $this->registry->formatVersion()) {
            throw new ImportException("The archive is of format $version, which no upcaster reads into {$this->registry->formatVersion()}.");
        }

        if (($manifest['restorable'] ?? false) !== true) {
            throw new ImportException('The archive is an export of a period, which can\'t be restored.');
        }

        return $manifest;
    }

    /**
     * Read the rows of every registered table, which the archive holds, checking each file against the manifest.
     *
     * @param array<string, mixed> $manifest
     *
     * @return array<string, array<int, array<string, string|null>>>
     */
    private function data(array $manifest): array
    {
        $listed = array_column($manifest['files'] ?? [], null, 'path');
        $data = [];

        foreach ($this->registry->tables() as $table) {
            $file = $listed[$table->archivePath()] ?? null;

            if ($file === null) {
                continue;
            }

            $csv = $this->zip->getFromName($this->entry($table->archivePath()));

            if ($csv === false || hash('sha256', $csv) !== $file['sha256']) {
                throw new ImportException("{$table->archivePath()} is missing or damaged.");
            }

            $rows = Csv::parse($csv);
            $header = array_shift($rows) ?? [];

            if (count($rows) !== (int) $file['rows']) {
                throw new ImportException("{$table->archivePath()} has " . count($rows) . " rows, the manifest {$file['rows']}.");
            }

            $data[$table->table()] = array_map(fn (array $row) => array_combine($header, $row), $rows);
        }

        return $data;
    }

    /**
     * Map the reference rows of the archive to the rows of this database, by their match columns.
     *
     * @param array<string, array<int, array<string, string|null>>> $data
     * @param SchemaInspector $schema
     * @param int $organizationId
     * @param ConnectionInterface $db
     *
     * @return void
     */
    private function matchReferences(array $data, SchemaInspector $schema, int $organizationId, ConnectionInterface $db): void
    {
        foreach ($this->registry->tables() as $table) {
            if (!$table->isReference()) {
                continue;
            }

            $name = $table->table();
            $match = $table->match() ?? throw new ImportException("The reference table $name has no columns to match on.");
            $this->maps[$name] = [];

            foreach ($data[$name] ?? [] as $row) {
                $query = $db->table($name);

                foreach ($match['columns'] as $column) {
                    $query->where($column, Values::import($table, $schema->columns($name)[$column], $row[$column]));
                }

                if ($match['within'] === 'organization') {
                    $query->where('organization_id', $organizationId);
                }

                if ($schema->hasColumn($name, 'deleted_at')) {
                    $query->whereNull('deleted_at');
                }

                $id = $query->value('id');

                if ($id === null && !$match['orNull']) {
                    $id = $this->references?->resolve($name, $row);
                }

                if ($id === null && !$match['orNull']) {
                    $values = implode(', ', array_map(fn (string $column) => "$column {$row[$column]}", $match['columns']));

                    throw new ImportException("This database has no $name row of $values, which the archive references.");
                }

                $this->maps[$name][(string) $row['id']] = $id === null ? null : (int) $id;
            }
        }
    }

    /**
     * Write the tenant's rows, set the deferred columns and rewrites, and verify the result.
     *
     * @param array<string, array<int, array<string, string|null>>> $data
     * @param SchemaInspector $schema
     * @param int $organizationId
     * @param ConnectionInterface $db
     * @param array<string, mixed> $manifest
     * @param bool $dryRun
     *
     * @return ImportResult
     */
    private function write(array $data, SchemaInspector $schema, int $organizationId, ConnectionInterface $db, array $manifest, bool $dryRun): ImportResult
    {
        $rows = [];
        $documents = 0;
        $skipped = 0;

        foreach ($this->registry->tables() as $table) {
            $name = $table->table();

            if ($table->isReference() || $table->isExportOnly() || !isset($data[$name])) {
                continue;
            }

            $this->maps[$name] ??= [];
            $rows[$name] = 0;

            foreach ($data[$name] as $row) {
                if ($table->documentColumns() !== null && ($row['archive_path'] ?? null) === null) {
                    // The archive was written without its documents: a row without its object isn't imported
                    $this->maps[$name][(string) $row['id']] = null;
                    $skipped++;

                    continue;
                }

                $this->maps[$name][(string) $row['id']] = $this->insert($table, $row, $schema, $organizationId, $db);
                $rows[$name]++;
                $documents += $table->documentColumns() !== null ? 1 : 0;
            }
        }

        foreach ($this->deferred as [$name, $id, $column, $referenced, $old]) {
            $db->table($name)->where('id', $id)->update([$column => $this->mapped($referenced, $old, "$name.$column")]);
        }

        foreach ($this->lateRewrites as [$table, $id, $rewrite, $value]) {
            $column = $schema->columns($table->table())[$rewrite->column];
            $db->table($table->table())->where('id', $id)->update([
                $rewrite->column => Values::import($table, $column, $this->rewrite($rewrite, $value, $table)),
            ]);
        }

        $tenantId = (int) $this->maps[$this->root->table()][(string) $data[$this->root->table()][0]['id']];
        $this->verify($tenantId, $rows, $schema, $db, $manifest);

        return new ImportResult(
            $dryRun ? null : $tenantId,
            $rows,
            $documents,
            $skipped,
            $manifest['checks'] ?? [],
            $dryRun,
            $manifest[$this->root->manifestKey()] ?? [],
            $dryRun ? [] : $this->uploaded,
        );
    }

    /**
     * Insert a row, and get its new id.
     *
     * @param TableSpec $table
     * @param array<string, string|null> $row
     * @param SchemaInspector $schema
     * @param int $organizationId
     * @param ConnectionInterface $db
     *
     * @return int
     */
    private function insert(TableSpec $table, array $row, SchemaInspector $schema, int $organizationId, ConnectionInterface $db): int
    {
        $name = $table->table();
        $row = $table->prepareImport($row);
        $late = [];

        foreach ($table->rewrites() as $rewrite) {
            if (!$rewrite->appliesTo($row)) {
                continue;
            }

            if ($this->positions[$rewrite->to] < $this->positions[$name]) {
                $row[$rewrite->column] = $this->rewrite($rewrite, $row[$rewrite->column] ?? null, $table);
            } else {
                $late[] = $rewrite;
            }
        }

        $foreignKeys = $schema->foreignKeys($name);
        $morph = $table->isMorph() ? $table->morphName() : null;
        $values = [];
        $deferred = [];

        foreach ($schema->columns($name) as $column => $info) {
            if ($column === 'id' || $info->generated || !array_key_exists($column, $row)) {
                continue;
            }

            $value = $row[$column];

            if ($table->isRoot() && $column === $this->root->organizationColumn()) {
                $values[$column] = $organizationId;
            } elseif (in_array($column, $table->deferredColumns(), true)) {
                $values[$column] = null;

                if ($value !== null) {
                    $deferred[] = [$column, $foreignKeys[$column] ?? throw new ImportException("The deferred $name.$column is no foreign key."), $value];
                }
            } elseif ($morph !== null && $column === "{$morph}_id") {
                $owner = MorphAliases::tableOf((string) $row["{$morph}_type"]) ?? throw new ImportException("$name.{$morph}_type {$row["{$morph}_type"]} maps no model.");
                $values[$column] = $this->mapped($owner, $value, "$name.$column");
            } elseif (isset($foreignKeys[$column]) && $value !== null) {
                $values[$column] = $this->mapped($foreignKeys[$column], $value, "$name.$column");
            } else {
                $values[$column] = Values::import($table, $info, $value);
            }
        }

        if ($table->documentColumns() !== null) {
            $values = [...$values, ...$this->upload($table, $row, $values)];
        }

        $id = (int) $db->table($name)->insertGetId($values);

        foreach ($deferred as [$column, $referenced, $old]) {
            $this->deferred[] = [$name, $id, $column, $referenced, $old];
        }

        foreach ($late as $rewrite) {
            $this->lateRewrites[] = [$table, $id, $rewrite, $row[$rewrite->column] ?? null];
        }

        return $id;
    }

    /**
     * Upload a row's document from the archive to the files disk, where its owner's files are, and get its columns.
     *
     * @param TableSpec $table
     * @param array<string, string|null> $row
     * @param array<string, mixed> $values The row's values to insert, with its owner remapped.
     *
     * @return array<string, string>
     */
    private function upload(TableSpec $table, array $row, array $values): array
    {
        ['disk' => $diskColumn, 'path' => $pathColumn] = $table->documentColumns();
        $contents = $this->zip->getFromName($this->entry((string) $row['archive_path']));

        if ($contents === false) {
            throw new ImportException("The document {$row['archive_path']} is missing from the archive.");
        }

        $disk = (string) config('api-core.files.disk');
        $folder = $table->isMorph()
            ? $row["{$table->morphName()}_type"] . '/' . $values["{$table->morphName()}_id"]
            : $table->table();
        $extension = pathinfo((string) $row[$pathColumn], PATHINFO_EXTENSION);
        $path = implode('/', array_filter([trim((string) config('api-core.files.directory'), '/'), $folder]))
            . '/' . Str::uuid() . ($extension === '' ? '' : ".$extension");

        if (!Storage::disk($disk)->put($path, $contents)) {
            throw new ImportException("The document {$row['archive_path']} couldn't be stored on the $disk disk.");
        }

        $this->uploaded[] = [$disk, $path];

        return [$diskColumn => $disk, $pathColumn => $path];
    }

    /**
     * Get the new id of a row of the archive.
     *
     * @param string $table
     * @param string|int $old
     * @param string $where Column, which references it, for the error.
     *
     * @return int|null
     */
    private function mapped(string $table, string|int $old, string $where): ?int
    {
        if (!isset($this->maps[$table])) {
            throw new ImportException("$where references $table, which the archive doesn't map.");
        }

        if (!array_key_exists((string) $old, $this->maps[$table])) {
            throw new ImportException("$where references the $table row $old, which isn't in the archive.");
        }

        return $this->maps[$table][(string) $old];
    }

    /**
     * Rewrite the ids inside a value.
     *
     * @param Rewrite $rewrite
     * @param string|null $value
     * @param TableSpec $table
     *
     * @return string|null
     */
    private function rewrite(Rewrite $rewrite, ?string $value, TableSpec $table): ?string
    {
        if ($value === null) {
            return null;
        }

        $where = "{$table->table()}.$rewrite->column";

        if ($rewrite->json !== null) {
            try {
                $decoded = json_decode($value, false, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new ImportException("$where isn't valid JSON.");
            }

            return Values::json($this->rewriteJson($decoded, $rewrite->jsonKeys(), $rewrite, $where));
        }

        return (string) preg_replace_callback($rewrite->pattern, function (array $match) use ($rewrite, $where) {
            [$whole, $start] = $match[0];
            [$id, $offset] = $match[1];

            return substr_replace($whole, (string) $this->mapped($rewrite->to, $id, $where), $offset - $start, strlen($id));
        }, $value, -1, $count, PREG_OFFSET_CAPTURE);
    }

    /**
     * Rewrite the ids at the keys of a decoded JSON value: one id or a list of them.
     *
     * @param mixed $node
     * @param array<int, string> $keys
     * @param Rewrite $rewrite
     * @param string $where
     *
     * @return mixed
     */
    private function rewriteJson(mixed $node, array $keys, Rewrite $rewrite, string $where): mixed
    {
        if ($keys === []) {
            return match (true) {
                $node === null => null,
                is_array($node) => array_map(fn (mixed $id) => $id === null ? null : $this->mapped($rewrite->to, $id, $where), $node),
                is_int($node) || (is_string($node) && ctype_digit($node)) => $this->mapped($rewrite->to, $node, $where),
                default => throw new ImportException("$where holds no id at $rewrite->json."),
            };
        }

        $key = array_shift($keys);

        if ($node instanceof stdClass && property_exists($node, $key)) {
            $node->$key = $this->rewriteJson($node->$key, $keys, $rewrite, $where);
        }

        return $node;
    }

    /**
     * Verify the imported tenant: its rows of each table, as the selection finds them, and its checks.
     *
     * @param int $tenantId
     * @param array<string, int> $rows
     * @param SchemaInspector $schema
     * @param ConnectionInterface $db
     * @param array<string, mixed> $manifest
     *
     * @return void
     */
    private function verify(int $tenantId, array $rows, SchemaInspector $schema, ConnectionInterface $db, array $manifest): void
    {
        $spec = new RunSpec(options: $manifest['options'] ?? []);
        $selection = new Selection($db, $this->registry, $this->root, $schema, $tenantId, $spec);

        foreach ($rows as $name => $count) {
            $found = $selection->query($selection->spec($name))->count();

            if ($found !== $count) {
                throw new ImportException("$name has $found rows of the imported tenant, $count were written.");
            }
        }

        $checks = json_decode(json_encode($this->checks->compute($tenantId, $db, $spec)), true);

        if ($checks !== ($manifest['checks'] ?? [])) {
            throw new ImportException('The figures of the imported tenant differ from the archive\'s: ' . json_encode($checks, JSON_UNESCAPED_UNICODE));
        }
    }

    /**
     * Delete the documents, which were uploaded.
     *
     * @return void
     */
    private function deleteUploaded(): void
    {
        foreach ($this->uploaded as [$disk, $path]) {
            Storage::disk($disk)->delete($path);
        }

        $this->uploaded = [];
    }
}
