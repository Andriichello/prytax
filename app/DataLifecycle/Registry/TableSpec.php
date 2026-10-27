<?php

namespace PryTax\DataLifecycle\Registry;

use Closure;
use InvalidArgumentException;
use PryTax\DataLifecycle\Backup\RunSpec;

/**
 * Class TableSpec.
 *
 * One table of a registry (backups.md §4.2): how its rows of a tenant are selected (its strategy), which file of the
 * archive holds them, and how its columns are treated. Every column is exported unless it is omitted (`all()` with
 * explicit exceptions, so a new column is never silently dropped, and the column snapshot makes it reviewed).
 *
 * Strategies:
 * - `root`: the tenant's own row (`profiles.id = ?`);
 * - `profile`: rows with the tenant's foreign key (`t.profile_id = ?`, the key of the TenantRoot);
 * - `child`: rows of an exported parent (`t.fk IN (the parent's rows)`), recursively;
 * - `morph`: rows owned through a type/id pair by any exported non-reference table with a morph alias;
 * - `profileJson`: rows, whose JSON column names the tenant (`audit_logs.metadata->$.profile_id`);
 * - `reference`: rows of a global table, which the exported rows reference; matched on import, never inserted.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Registry
 */
final class TableSpec
{
    public const string ROOT = 'root';

    public const string PROFILE = 'profile';

    public const string CHILD = 'child';

    public const string MORPH = 'morph';

    public const string PROFILE_JSON = 'profile_json';

    public const string REFERENCE = 'reference';

    /**
     * Name of the archive's file, without `data/` and `.csv`.
     *
     * @var string
     */
    private string $file;

    /**
     * Parent table and the column, which references it, of a child.
     *
     * @var array{table: string, column: string}|null
     */
    private ?array $parent = null;

    /**
     * Name of the type/id pair of a morph table (`model` for `model_type`, `model_id`).
     *
     * @var string|null
     */
    private ?string $morph = null;

    /**
     * JSON column and path, which hold the tenant's id, of a profileJson table.
     *
     * @var array{column: string, path: string}|null
     */
    private ?array $json = null;

    /**
     * Columns (`table.column`, or `*.column` for every exported table with it), through which the exported rows
     * reference a reference table.
     *
     * @var array<int, string>
     */
    private array $via = [];

    /**
     * Morph pairs of exported tables, through which they reference a reference table.
     *
     * @var array<int, array{table: string, morph: string, alias: string}>
     */
    private array $viaMorphs = [];

    /**
     * Encrypted columns, exported decrypted, with whether they hold JSON.
     *
     * @var array<string, bool>
     */
    private array $decrypted = [];

    /**
     * Columns, which are never exported (secrets, derived values).
     *
     * @var array<int, string>
     */
    private array $omitted = [];

    /**
     * Generated columns: exported for reading, never imported.
     *
     * @var array<int, string>
     */
    private array $generated = [];

    /**
     * Columns, to which the export is limited, or null for all of them.
     *
     * @var array<int, string>|null
     */
    private ?array $only = null;

    /**
     * Foreign keys, which an import sets after every table is in, breaking a cycle.
     *
     * @var array<int, string>
     */
    private array $deferred = [];

    /**
     * Ids inside values, which an import rewrites.
     *
     * @var array<int, Rewrite>
     */
    private array $rewrites = [];

    /**
     * Determines if the table is exported and purged, but never imported.
     *
     * @var bool
     */
    private bool $exportOnly = false;

    /**
     * Option of the run, without which the table isn't exported.
     *
     * @var string|null
     */
    private ?string $optional = null;

    /**
     * Columns of a table of documents: the disk and the path of each row's object.
     *
     * @var array{disk: string, path: string}|null
     */
    private ?array $documents = null;

    /**
     * Columns, by which a reference row is matched on import, and where.
     *
     * @var array{columns: array<int, string>, within: string|null, orNull: bool}|null
     */
    private ?array $match = null;

    /**
     * Callback, which completes a row before it is imported, such as recomputing an omitted hash.
     *
     * @var Closure(array<string, mixed>): array<string, mixed>|null
     */
    private ?Closure $onImport = null;

    /**
     * TableSpec constructor.
     *
     * @param string $table
     * @param string $strategy
     */
    private function __construct(
        private readonly string $table,
        private readonly string $strategy,
    ) {
        $this->file = $strategy === self::REFERENCE ? "reference/$table" : $table;
    }

    /**
     * The tenant's own row.
     *
     * @param string $table
     *
     * @return self
     */
    public static function root(string $table): self
    {
        return new self($table, self::ROOT);
    }

    /**
     * Rows with the tenant's foreign key.
     *
     * @param string $table
     *
     * @return self
     */
    public static function profile(string $table): self
    {
        return new self($table, self::PROFILE);
    }

    /**
     * Rows of an exported parent, which the column references.
     *
     * @param string $table
     * @param string $parent
     * @param string $fk
     *
     * @return self
     */
    public static function child(string $table, string $parent, string $fk): self
    {
        $spec = new self($table, self::CHILD);
        $spec->parent = ['table' => $parent, 'column' => $fk];

        return $spec;
    }

    /**
     * Rows owned through the `{morph}_type` / `{morph}_id` pair by exported rows of other tables.
     *
     * @param string $table
     * @param string $morph
     *
     * @return self
     */
    public static function morph(string $table, string $morph): self
    {
        $spec = new self($table, self::MORPH);
        $spec->morph = $morph;

        return $spec;
    }

    /**
     * Rows, whose JSON column holds the tenant's id at the path.
     *
     * @param string $table
     * @param string $column
     * @param string $path
     *
     * @return self
     */
    public static function profileJson(string $table, string $column, string $path): self
    {
        $spec = new self($table, self::PROFILE_JSON);
        $spec->json = ['column' => $column, 'path' => $path];

        return $spec;
    }

    /**
     * Rows of a global table, which exported rows reference through the columns.
     *
     * @param string $table
     * @param array<int, string> $via `table.column`, or `*.column` for every exported table with the column.
     *
     * @return self
     */
    public static function reference(string $table, array $via): self
    {
        $spec = new self($table, self::REFERENCE);
        $spec->via = array_values($via);

        return $spec;
    }

    /**
     * Name the archive's file (`data/{name}.csv`).
     *
     * @param string $name
     *
     * @return self
     */
    public function file(string $name): self
    {
        $this->file = $name;

        return $this;
    }

    /**
     * Export the encrypted columns decrypted, and encrypt them again on import.
     *
     * @param string|array<int, string> $columns
     * @param bool $json Whether they hold JSON (an `encrypted:array` cast), which is validated and written compact.
     *
     * @return self
     */
    public function decrypt(string|array $columns, bool $json = false): self
    {
        foreach ((array) $columns as $column) {
            $this->decrypted[$column] = $json;
        }

        return $this;
    }

    /**
     * Never export the columns.
     *
     * @param string ...$columns
     *
     * @return self
     */
    public function omit(string ...$columns): self
    {
        $this->omitted = [...$this->omitted, ...$columns];

        return $this;
    }

    /**
     * Mark the columns generated: exported for reading, skipped on import.
     *
     * @param string ...$columns
     *
     * @return self
     */
    public function generated(string ...$columns): self
    {
        $this->generated = [...$this->generated, ...$columns];

        return $this;
    }

    /**
     * Export only the columns.
     *
     * @param string ...$columns
     *
     * @return self
     */
    public function only(string ...$columns): self
    {
        $this->only = $columns;

        return $this;
    }

    /**
     * Set the foreign keys after every table is imported, and null them first on purge, breaking a cycle.
     *
     * @param string ...$columns
     *
     * @return self
     */
    public function deferred(string ...$columns): self
    {
        $this->deferred = [...$this->deferred, ...$columns];

        return $this;
    }

    /**
     * Rewrite the ids, which sit inside the column's value, through the id map of the table `to`.
     *
     * @param string $column
     * @param string $to
     * @param string|null $json JSON path of the ids, `$` for the whole value.
     * @param string|null $pattern Pattern with one group of digits.
     * @param array<string, array<int, string>> $when Columns of the rows, to which it applies, with their values.
     *
     * @return self
     */
    public function rewrite(string $column, string $to, ?string $json = null, ?string $pattern = null, array $when = []): self
    {
        $this->rewrites[] = new Rewrite($column, $to, $json, $pattern, $when);

        return $this;
    }

    /**
     * Export and purge the table, but never import it (derived data, or a read-only history).
     *
     * @return self
     */
    public function exportOnly(): self
    {
        $this->exportOnly = true;

        return $this;
    }

    /**
     * Export the table only, when the run has the option.
     *
     * @param string $option
     *
     * @return self
     */
    public function optional(string $option): self
    {
        $this->optional = $option;

        return $this;
    }

    /**
     * Mark the table one of documents, each row an object on its disk at its path, which the archive holds under
     * `documents/` (its `archive_path` column).
     *
     * @param string $disk
     * @param string $path
     *
     * @return self
     */
    public function documents(string $disk = 'disk', string $path = 'path'): self
    {
        $this->documents = ['disk' => $disk, 'path' => $path];

        return $this;
    }

    /**
     * Match a reference row on import by the columns, rather than inserting it.
     *
     * @param array<int, string>|string $columns
     * @param string|null $within `organization`: only among the target organization's rows.
     * @param bool $orNull A row without a match maps to NULL; otherwise the import fails.
     *
     * @return self
     */
    public function matchOn(array|string $columns, ?string $within = null, bool $orNull = false): self
    {
        $this->match = ['columns' => (array) $columns, 'within' => $within, 'orNull' => $orNull];

        return $this;
    }

    /**
     * Also select the reference rows, which an exported table's morph pair names with the alias.
     *
     * @param string $table
     * @param string $morph
     * @param string $alias
     *
     * @return self
     */
    public function viaMorph(string $table, string $morph, string $alias): self
    {
        $this->viaMorphs[] = ['table' => $table, 'morph' => $morph, 'alias' => $alias];

        return $this;
    }

    /**
     * Complete each row before it is imported, with the values of the archive (decrypted).
     *
     * @param Closure(array<string, mixed>): array<string, mixed> $callback
     *
     * @return self
     */
    public function onImport(Closure $callback): self
    {
        $this->onImport = $callback;

        return $this;
    }

    /**
     * Get name of the table.
     *
     * @return string
     */
    public function table(): string
    {
        return $this->table;
    }

    /**
     * Get the strategy.
     *
     * @return string
     */
    public function strategy(): string
    {
        return $this->strategy;
    }

    /**
     * Determine if the table is the tenant's root.
     *
     * @return bool
     */
    public function isRoot(): bool
    {
        return $this->strategy === self::ROOT;
    }

    /**
     * Determine if the table is a global one, whose rows are referenced.
     *
     * @return bool
     */
    public function isReference(): bool
    {
        return $this->strategy === self::REFERENCE;
    }

    /**
     * Determine if the table reaches its owners through a morph pair.
     *
     * @return bool
     */
    public function isMorph(): bool
    {
        return $this->strategy === self::MORPH;
    }

    /**
     * Determine if the table's rows may own rows of morph tables: a root, profile or child table.
     *
     * @return bool
     */
    public function canOwnMorphs(): bool
    {
        return in_array($this->strategy, [self::ROOT, self::PROFILE, self::CHILD], true);
    }

    /**
     * Get the parent of a child.
     *
     * @return array{table: string, column: string}|null
     */
    public function parent(): ?array
    {
        return $this->parent;
    }

    /**
     * Get name of the morph pair.
     *
     * @return string|null
     */
    public function morphName(): ?string
    {
        return $this->morph;
    }

    /**
     * Get the JSON column and path of a profileJson table.
     *
     * @return array{column: string, path: string}|null
     */
    public function json(): ?array
    {
        return $this->json;
    }

    /**
     * Get the columns, through which a reference table is referenced.
     *
     * @return array<int, string>
     */
    public function via(): array
    {
        return $this->via;
    }

    /**
     * Get the morph pairs, through which a reference table is referenced.
     *
     * @return array<int, array{table: string, morph: string, alias: string}>
     */
    public function viaMorphs(): array
    {
        return $this->viaMorphs;
    }

    /**
     * Get the decrypted columns, with whether they hold JSON.
     *
     * @return array<string, bool>
     */
    public function decrypted(): array
    {
        return $this->decrypted;
    }

    /**
     * Get the omitted columns.
     *
     * @return array<int, string>
     */
    public function omitted(): array
    {
        return $this->omitted;
    }

    /**
     * Get the generated columns.
     *
     * @return array<int, string>
     */
    public function generatedColumns(): array
    {
        return $this->generated;
    }

    /**
     * Get the deferred columns.
     *
     * @return array<int, string>
     */
    public function deferredColumns(): array
    {
        return $this->deferred;
    }

    /**
     * Get the rewrites.
     *
     * @return array<int, Rewrite>
     */
    public function rewrites(): array
    {
        return $this->rewrites;
    }

    /**
     * Determine if the table is never imported.
     *
     * @return bool
     */
    public function isExportOnly(): bool
    {
        return $this->exportOnly;
    }

    /**
     * Get the run's option, which the table needs.
     *
     * @return string|null
     */
    public function optionalOn(): ?string
    {
        return $this->optional;
    }

    /**
     * Get the columns of the documents' disk and path.
     *
     * @return array{disk: string, path: string}|null
     */
    public function documentColumns(): ?array
    {
        return $this->documents;
    }

    /**
     * Get how a reference row is matched.
     *
     * @return array{columns: array<int, string>, within: string|null, orNull: bool}|null
     */
    public function match(): ?array
    {
        return $this->match;
    }

    /**
     * Complete a row before it is imported.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public function prepareImport(array $row): array
    {
        return $this->onImport ? ($this->onImport)($row) : $row;
    }

    /**
     * Get the path of the table's file in the archive.
     *
     * @return string
     */
    public function archivePath(): string
    {
        return "data/{$this->file}.csv";
    }

    /**
     * Determine if the run exports the table.
     *
     * @param RunSpec $spec
     *
     * @return bool
     */
    public function enabledFor(RunSpec $spec): bool
    {
        return $this->optional === null || $spec->has($this->optional);
    }

    /**
     * Get the exported columns of the table's columns: all of them in their order, without the omitted ones, or
     * only the listed ones.
     *
     * @param array<int, string> $columns
     *
     * @return array<int, string>
     */
    public function exportedColumns(array $columns): array
    {
        if ($this->only !== null) {
            $missing = array_diff($this->only, $columns);

            if ($missing !== []) {
                throw new InvalidArgumentException("$this->table has no columns " . implode(', ', $missing) . '.');
            }
        }

        return array_values(array_filter(
            $columns,
            fn (string $column) => !in_array($column, $this->omitted, true)
                && ($this->only === null || in_array($column, $this->only, true))
        ));
    }

    /**
     * Get the treatment of an exported column, as the column snapshot and the schema describe it.
     *
     * @param string $column
     *
     * @return string
     */
    public function treatmentOf(string $column): string
    {
        return match (true) {
            in_array($column, $this->omitted, true) || ($this->only !== null && !in_array($column, $this->only, true)) => 'omit',
            isset($this->decrypted[$column]) => $this->decrypted[$column] ? 'decrypt:json' : 'decrypt',
            in_array($column, $this->generated, true) => 'generated',
            in_array($column, $this->deferred, true) => 'deferred',
            default => 'export',
        };
    }

    /**
     * Get the table's definition as plain values, for the registry's hash.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'table' => $this->table,
            'strategy' => $this->strategy,
            'file' => $this->archivePath(),
            'parent' => $this->parent,
            'morph' => $this->morph,
            'json' => $this->json,
            'via' => $this->via,
            'via_morphs' => $this->viaMorphs,
            'decrypted' => $this->decrypted,
            'omitted' => $this->omitted,
            'generated' => $this->generated,
            'only' => $this->only,
            'deferred' => $this->deferred,
            'rewrites' => array_map(fn (Rewrite $rewrite) => $rewrite->toArray(), $this->rewrites),
            'export_only' => $this->exportOnly ?: null,
            'optional' => $this->optional,
            'documents' => $this->documents,
            'match' => $this->match,
            'on_import' => $this->onImport ? true : null,
        ], fn (mixed $value) => $value !== null && $value !== []);
    }
}
