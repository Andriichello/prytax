<?php

namespace PryTax\DataLifecycle\Backup\Internal;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Storage;
use PryTax\DataLifecycle\Backup\RunSpec;
use PryTax\DataLifecycle\Contracts\ArchiveChecks;
use PryTax\DataLifecycle\Contracts\TableRegistry;
use PryTax\DataLifecycle\Contracts\TenantRoot;
use PryTax\DataLifecycle\Registry\Column;
use PryTax\DataLifecycle\Registry\MorphAliases;
use PryTax\DataLifecycle\Registry\RegistryHasher;
use PryTax\DataLifecycle\Registry\SchemaInspector;
use PryTax\DataLifecycle\Registry\TableSpec;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Class ArchiveWriter.
 *
 * Writes a tenant's archive (backups.md §2.3, §4.4) in two steps: its data inside the run's snapshot (one CSV per
 * table in registry order, the manifest with the checks, `schema.json`, `README.txt`), with the documents only
 * listed; then, after the snapshot is committed and only for a tenant whose data changed, its documents, each read
 * through its row's own disk. Archives are zips (deflate), built with ZipArchive on the worker's disk.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Backup\Internal
 */
final class ArchiveWriter
{
    /**
     * Format, which the manifest names.
     *
     * @var string
     */
    public const string FORMAT = 'prytax-backup';

    /**
     * ArchiveWriter constructor.
     *
     * @param TableRegistry $registry
     * @param TenantRoot $root
     * @param ArchiveChecks $checks
     */
    public function __construct(
        private readonly TableRegistry $registry,
        private readonly TenantRoot $root,
        private readonly ArchiveChecks $checks,
    ) {}

    /**
     * Write the tenant's data into a local zip, reading it on the connection (the run's snapshot).
     *
     * @param int $tenantId
     * @param RunSpec $spec
     * @param ConnectionInterface $db
     * @param array{run_id?: string|null, snapshot_at: string, organization: array{id: int, name: string}} $context
     *
     * @return DataArchive
     * @throws RuntimeException When the tenant isn't found, a value can't be exported, or the zip can't be written.
     */
    public function writeData(int $tenantId, RunSpec $spec, ConnectionInterface $db, array $context): DataArchive
    {
        $schema = new SchemaInspector($db);
        $selection = new Selection($db, $this->registry, $this->root, $schema, $tenantId, $spec);
        $path = (string) tempnam(sys_get_temp_dir(), 'pbk');
        $zip = $this->open($path, ZipArchive::OVERWRITE);

        $files = [];
        $documents = [];
        $rootRow = null;

        try {
            foreach ($this->registry->tables() as $table) {
                if (!$table->enabledFor($spec)) {
                    continue;
                }

                $columns = $this->columnsOf($table, $schema);
                $header = array_keys($columns);

                if ($table->documentColumns() !== null) {
                    $header[] = 'archive_path';
                }

                // A period export marks the rows, which it takes only so the period reads on its own
                $marksScope = $spec->isPeriod() && $table->periodVia() !== [];

                if ($marksScope) {
                    $header[] = 'in_scope';
                }

                $csv = Csv::BOM . Csv::line($header);
                $rows = 0;

                foreach ($selection->query($table)->lazyById(1000, 'id') as $row) {
                    $values = [];

                    foreach ($columns as $name => $column) {
                        $values[$name] = Values::export($table, $column, $row->$name);
                    }

                    if ($table->documentColumns() !== null) {
                        $document = $spec->includesDocuments() ? $this->document($table, $row) : null;
                        $values['archive_path'] = $document?->archivePath;

                        if ($document) {
                            $documents[] = $document;
                        }
                    }

                    if ($marksScope) {
                        $values['in_scope'] = $selection->isInPeriod($table, $row) ? '1' : '0';
                    }

                    if ($table->isRoot()) {
                        $rootRow = $values;
                    }

                    $csv .= Csv::line(array_values($values));
                    $rows++;
                }

                $zip->addFromString($table->archivePath(), $csv);
                $zip->setCompressionName($table->archivePath(), ZipArchive::CM_DEFLATE);
                $files[] = ['path' => $table->archivePath(), 'rows' => $rows, 'sha256' => hash('sha256', $csv), 'bytes' => strlen($csv)];
            }

            if ($rootRow === null) {
                throw new RuntimeException("The tenant $tenantId isn't found in {$this->root->table()}.");
            }

            $manifest = $this->manifest($tenantId, $spec, $db, $schema, $context, $rootRow, $files, $documents);

            $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $zip->addFromString('schema.json', $this->schemaJson($schema, $spec));
            $zip->addFromString('README.txt', view('data-lifecycle::readme', ['manifest' => $manifest])->render());

            if (!$zip->close()) {
                throw new RuntimeException("The archive couldn't be written to $path.");
            }
        } catch (Throwable $exception) {
            @$zip->close();
            @unlink($path);

            throw $exception;
        }

        return new DataArchive($path, $manifest['content_hash'], $documents, $manifest, $this->root->folderName($rootRow));
    }

    /**
     * Add the documents, which the data lists, to the archive, each read through its own disk, and get its file.
     *
     * @param DataArchive $archive
     *
     * @return ArchiveFile
     * @throws RuntimeException When a document is missing (deleted after the snapshot): the archive never silently
     *                          lacks a file, which its data lists.
     */
    public function addDocuments(DataArchive $archive): ArchiveFile
    {
        $temporary = [];

        try {
            if ($archive->documents !== []) {
                $zip = $this->open($archive->path);

                foreach ($archive->documents as $document) {
                    $stream = Storage::disk($document->disk)->readStream($document->path);

                    if (!is_resource($stream)) {
                        throw new RuntimeException("The document $document->archivePath is missing from its disk.");
                    }

                    $local = (string) tempnam(sys_get_temp_dir(), 'pdc');
                    $temporary[] = $local;
                    $target = fopen($local, 'w');
                    stream_copy_to_stream($stream, $target);
                    fclose($target);
                    fclose($stream);

                    $zip->addFile($local, $document->archivePath);
                    // PDFs and images are compressed already
                    $zip->setCompressionName($document->archivePath, ZipArchive::CM_STORE);
                }

                if (!$zip->close()) {
                    throw new RuntimeException("The documents couldn't be added to $archive->path.");
                }
            }
        } finally {
            array_map('unlink', array_filter($temporary, 'is_file'));
        }

        clearstatcache(true, $archive->path);

        return new ArchiveFile($archive->path, hash_file('sha256', $archive->path), (int) filesize($archive->path));
    }

    /**
     * Get the exported columns of the table, in their order.
     *
     * @param TableSpec $table
     * @param SchemaInspector $schema
     *
     * @return array<string, Column>
     */
    private function columnsOf(TableSpec $table, SchemaInspector $schema): array
    {
        $all = $schema->columns($table->table());

        if ($all === []) {
            throw new RuntimeException("The table {$table->table()} doesn't exist.");
        }

        $columns = [];

        foreach ($table->exportedColumns(array_keys($all)) as $name) {
            $columns[$name] = $all[$name];
        }

        return $columns;
    }

    /**
     * Get the document of a row of a table of documents: `documents/{owner's table}/{owner's id}/{id}-{name}`, from
     * the morph pair, which owns it.
     *
     * @param TableSpec $table
     * @param object $row
     *
     * @return Document
     */
    private function document(TableSpec $table, object $row): Document
    {
        ['disk' => $disk, 'path' => $path] = $table->documentColumns();
        $name = isset($row->name) && $row->name !== '' ? $row->name : basename((string) $row->$path);
        $folder = $table->table();

        if ($table->isMorph()) {
            $morph = $table->morphName();
            $owner = MorphAliases::tableOf((string) $row->{"{$morph}_type"}) ?? 'other';
            $folder = $owner . '/' . $row->{"{$morph}_id"};
        }

        return new Document((string) $row->$disk, (string) $row->$path, "documents/$folder/{$row->id}-$name", (int) ($row->size ?? 0));
    }

    /**
     * Build the manifest (backups.md §2.6).
     *
     * @param int $tenantId
     * @param RunSpec $spec
     * @param ConnectionInterface $db
     * @param SchemaInspector $schema
     * @param array $context
     * @param array<string, string|null> $rootRow
     * @param array<int, array{path: string, rows: int, sha256: string, bytes: int}> $files
     * @param array<int, Document> $documents
     *
     * @return array<string, mixed>
     */
    private function manifest(
        int $tenantId,
        RunSpec $spec,
        ConnectionInterface $db,
        SchemaInspector $schema,
        array $context,
        array $rootRow,
        array $files,
        array $documents
    ): array {
        $hashes = array_column($files, 'sha256', 'path');
        ksort($hashes);

        return [
            'format' => self::FORMAT,
            'format_version' => $this->registry->formatVersion(),
            'registry_hash' => RegistryHasher::hash($this->registry, $schema),
            'schema_version' => $schema->hasTable('migrations') ? $db->table('migrations')->max('migration') : null,
            'app_version' => (string) config('data-lifecycle.app_version'),
            'kind' => $spec->kind,
            'restorable' => $spec->isRestorable(),
            'scope' => $spec->scope(),
            'snapshot_at' => $context['snapshot_at'],
            'run_id' => $context['run_id'] ?? null,
            'organization' => $context['organization'],
            $this->root->manifestKey() => $this->root->describe($rootRow),
            'options' => $spec->options,
            'files' => $files,
            'documents' => [
                'count' => count($documents),
                'bytes' => array_sum(array_map(fn (Document $document) => $document->bytes, $documents)),
            ],
            'checks' => $this->checks->compute($tenantId, $db, $spec),
            // Of the data only, so an unchanged tenant has the same hash in every run
            'content_hash' => hash('sha256', implode("\n", array_map(fn (string $path, string $hash) => "$path:$hash", array_keys($hashes), $hashes))),
        ];
    }

    /**
     * Describe the archive's tables and columns for its readers (`schema.json`).
     *
     * @param SchemaInspector $schema
     * @param RunSpec $spec
     *
     * @return string
     */
    private function schemaJson(SchemaInspector $schema, RunSpec $spec): string
    {
        $tables = [];

        foreach ($this->registry->tables() as $table) {
            if (!$table->enabledFor($spec)) {
                continue;
            }

            $columns = [];

            foreach ($this->columnsOf($table, $schema) as $name => $column) {
                $columns[] = [
                    'name' => $name,
                    'type' => $column->type,
                    'nullable' => $column->nullable,
                    'generated' => $column->generated,
                    'treatment' => $table->treatmentOf($name),
                ];
            }

            $tables[] = [
                'table' => $table->table(),
                'file' => $table->archivePath(),
                'strategy' => $table->strategy(),
                'imported' => !$table->isReference() && !$table->isExportOnly(),
                'columns' => $columns,
            ];
        }

        return json_encode([
            'format' => self::FORMAT,
            'format_version' => $this->registry->formatVersion(),
            'csv' => [
                'encoding' => 'UTF-8 with BOM',
                'separator' => ',',
                'quote' => '"',
                'line_break' => "\r\n",
                'null' => 'an empty unquoted field; an empty string is ""',
                'timestamps' => 'ISO 8601 in UTC, such as 2026-10-27T02:37:11Z',
                'decimals' => 'as stored, with a dot and no grouping',
                'booleans' => '1 / 0',
                'json' => 'compact JSON strings',
            ],
            'tables' => $tables,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Open a zip.
     *
     * @param string $path
     * @param int $flags
     *
     * @return ZipArchive
     */
    private function open(string $path, int $flags = 0): ZipArchive
    {
        $zip = new ZipArchive;
        $result = $zip->open($path, $flags);

        if ($result !== true) {
            throw new RuntimeException("The archive $path couldn't be opened ($result).");
        }

        return $zip;
    }
}
