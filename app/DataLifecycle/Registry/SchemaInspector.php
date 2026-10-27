<?php

namespace PryTax\DataLifecycle\Registry;

use Illuminate\Database\ConnectionInterface;

/**
 * Class SchemaInspector.
 *
 * Reads the tables, columns and foreign keys of the connection's database from `information_schema` (MySQL), once,
 * for the registry's tests (completeness, order, generated columns), its column snapshot and the archives.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Registry
 */
final class SchemaInspector
{
    /**
     * Columns of every table, in their order.
     *
     * @var array<string, array<string, Column>>|null
     */
    private ?array $columns = null;

    /**
     * Foreign keys of every table: its column and the table it references.
     *
     * @var array<string, array<string, string>>|null
     */
    private ?array $foreignKeys = null;

    /**
     * SchemaInspector constructor.
     *
     * @param ConnectionInterface $db
     */
    public function __construct(private readonly ConnectionInterface $db) {}

    /**
     * Get names of the tables.
     *
     * @return array<int, string>
     */
    public function tables(): array
    {
        return array_keys($this->allColumns());
    }

    /**
     * Determine if the table exists.
     *
     * @param string $table
     *
     * @return bool
     */
    public function hasTable(string $table): bool
    {
        return isset($this->allColumns()[$table]);
    }

    /**
     * Get the columns of the table, in their order.
     *
     * @param string $table
     *
     * @return array<string, Column>
     */
    public function columns(string $table): array
    {
        return $this->allColumns()[$table] ?? [];
    }

    /**
     * Get names of the table's columns, in their order.
     *
     * @param string $table
     *
     * @return array<int, string>
     */
    public function columnNames(string $table): array
    {
        return array_keys($this->columns($table));
    }

    /**
     * Determine if the table has the column.
     *
     * @param string $table
     * @param string $column
     *
     * @return bool
     */
    public function hasColumn(string $table, string $column): bool
    {
        return isset($this->columns($table)[$column]);
    }

    /**
     * Get names of the table's generated columns.
     *
     * @param string $table
     *
     * @return array<int, string>
     */
    public function generatedColumns(string $table): array
    {
        return array_keys(array_filter($this->columns($table), fn (Column $column) => $column->generated));
    }

    /**
     * Get names of the table's morph pairs: `{name}_type` with `{name}_id`.
     *
     * @param string $table
     *
     * @return array<int, string>
     */
    public function morphPairs(string $table): array
    {
        $pairs = [];

        foreach ($this->columnNames($table) as $column) {
            if (str_ends_with($column, '_type') && $this->hasColumn($table, substr($column, 0, -5) . '_id')) {
                $pairs[] = substr($column, 0, -5);
            }
        }

        return $pairs;
    }

    /**
     * Get the foreign keys of the table: its column and the table it references.
     *
     * @param string $table
     *
     * @return array<string, string>
     */
    public function foreignKeys(string $table): array
    {
        if ($this->foreignKeys === null) {
            $this->foreignKeys = [];

            $rows = $this->db->select(
                'SELECT TABLE_NAME AS t, COLUMN_NAME AS c, REFERENCED_TABLE_NAME AS r FROM information_schema.KEY_COLUMN_USAGE '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY TABLE_NAME, COLUMN_NAME'
            );

            foreach ($rows as $row) {
                $this->foreignKeys[$row->t][$row->c] = $row->r;
            }
        }

        return $this->foreignKeys[$table] ?? [];
    }

    /**
     * Load the columns of every table.
     *
     * @return array<string, array<string, Column>>
     */
    private function allColumns(): array
    {
        if ($this->columns === null) {
            $this->columns = [];

            $rows = $this->db->select(
                'SELECT TABLE_NAME AS t, COLUMN_NAME AS c, DATA_TYPE AS type, IS_NULLABLE AS n, EXTRA AS extra '
                . 'FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, ORDINAL_POSITION'
            );

            foreach ($rows as $row) {
                // DEFAULT_GENERATED marks a default such as CURRENT_TIMESTAMP, not a generated column
                $generated = (bool) preg_match('/\b(VIRTUAL|STORED) GENERATED\b/i', (string) $row->extra);

                $this->columns[$row->t][$row->c] = new Column($row->c, strtolower($row->type), $row->n === 'YES', $generated);
            }
        }

        return $this->columns;
    }
}
