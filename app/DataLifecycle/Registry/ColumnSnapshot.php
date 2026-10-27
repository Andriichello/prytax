<?php

namespace PryTax\DataLifecycle\Registry;

use PryTax\DataLifecycle\Contracts\TableRegistry;

/**
 * Class ColumnSnapshot.
 *
 * Every column of every registered table with its treatment, and the excluded tables (backups.md §4.2, D11). A
 * committed copy makes a migration, which adds, renames or drops a column of a registered table (an api-core
 * upgrade too), fail the tests until the column is reviewed and the copy regenerated. Comparing the two also tells
 * the kind of change: a removed table or column, a changed file, strategy or treatment is breaking and needs a new
 * `format_version`; an added table or column isn't.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Registry
 */
final class ColumnSnapshot
{
    /**
     * Take the snapshot of the registry against the database's columns.
     *
     * @param TableRegistry $registry
     * @param SchemaInspector $schema
     *
     * @return array{format_version: int, tables: array<string, array{file: string, strategy: string, columns: array<string, string>}>, excluded: array<string, string>}
     */
    public static function of(TableRegistry $registry, SchemaInspector $schema): array
    {
        $tables = [];

        foreach ($registry->tables() as $table) {
            $columns = [];

            foreach ($schema->columnNames($table->table()) as $column) {
                $columns[$column] = $table->treatmentOf($column);
            }

            if ($table->documentColumns() !== null) {
                $columns['archive_path'] = 'document';
            }

            $tables[$table->table()] = [
                'file' => $table->archivePath(),
                'strategy' => $table->strategy() . ($table->isExportOnly() ? ':export-only' : ''),
                'columns' => $columns,
            ];
        }

        $excluded = $registry->excluded();
        ksort($excluded);

        return ['format_version' => $registry->formatVersion(), 'tables' => $tables, 'excluded' => $excluded];
    }

    /**
     * Compare a committed snapshot with the current one.
     *
     * @param array $committed
     * @param array $current
     *
     * @return array{breaking: array<int, string>, additive: array<int, string>}
     */
    public static function diff(array $committed, array $current): array
    {
        $breaking = [];
        $additive = [];

        foreach ($committed['tables'] ?? [] as $name => $table) {
            $now = $current['tables'][$name] ?? null;

            if ($now === null) {
                $breaking[] = "$name: removed";

                continue;
            }

            foreach (['file', 'strategy'] as $key) {
                if ($table[$key] !== $now[$key]) {
                    $breaking[] = "$name: $key {$table[$key]} → {$now[$key]}";
                }
            }

            foreach ($table['columns'] as $column => $treatment) {
                $treatmentNow = $now['columns'][$column] ?? null;

                if ($treatmentNow === null) {
                    $breaking[] = "$name.$column: removed";
                } elseif ($treatmentNow !== $treatment) {
                    $breaking[] = "$name.$column: $treatment → $treatmentNow";
                }
            }

            foreach (array_diff_key($now['columns'], $table['columns']) as $column => $treatment) {
                $additive[] = "$name.$column: added ($treatment)";
            }
        }

        foreach (array_diff_key($current['tables'] ?? [], $committed['tables'] ?? []) as $name => $table) {
            $additive[] = "$name: added";
        }

        if (($committed['excluded'] ?? []) !== ($current['excluded'] ?? [])) {
            $additive[] = 'excluded tables changed';
        }

        return ['breaking' => $breaking, 'additive' => $additive];
    }
}
