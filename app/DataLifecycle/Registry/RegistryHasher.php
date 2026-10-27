<?php

namespace PryTax\DataLifecycle\Registry;

use PryTax\DataLifecycle\Contracts\TableRegistry;

/**
 * Class RegistryHasher.
 *
 * The registry's hash (backups.md §4.2, D11): sha256 of its normalized definition, the tables in their order with
 * their strategies and treatments, and the columns of each. Every backup stores it, so an archive tells which
 * definition wrote it.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Registry
 */
final class RegistryHasher
{
    /**
     * Get the hash of the registry against the database's columns.
     *
     * @param TableRegistry $registry
     * @param SchemaInspector $schema
     *
     * @return string
     */
    public static function hash(TableRegistry $registry, SchemaInspector $schema): string
    {
        $snapshot = ColumnSnapshot::of($registry, $schema);
        $tables = [];

        foreach ($registry->tables() as $table) {
            $tables[] = [...$table->toArray(), 'columns' => $snapshot['tables'][$table->table()]['columns']];
        }

        $normalized = self::sortKeys(['format_version' => $registry->formatVersion(), 'tables' => $tables]);

        return hash('sha256', json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Sort the keys of associative arrays, recursively, keeping lists in their order.
     *
     * @param mixed $value
     *
     * @return mixed
     */
    private static function sortKeys(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (!array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $item) => self::sortKeys($item), $value);
    }
}
