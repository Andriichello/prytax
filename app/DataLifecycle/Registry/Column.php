<?php

namespace PryTax\DataLifecycle\Registry;

/**
 * Class Column.
 *
 * A column of a table, as `information_schema.COLUMNS` (MySQL) or the schema builder (SQLite) describes it.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Registry
 */
final class Column
{
    /**
     * Column constructor.
     *
     * @param string $name
     * @param string $type Data type, such as `varchar`, `decimal`, `timestamp`, `json`.
     * @param bool $nullable
     * @param bool $generated Whether it is a virtual or stored generated column.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly bool $nullable,
        public readonly bool $generated,
    ) {}

    /**
     * Determine if the column holds a point in time, which an archive writes in UTC.
     *
     * @return bool
     */
    public function isTimestamp(): bool
    {
        return in_array($this->type, ['timestamp', 'datetime'], true);
    }

    /**
     * Determine if the column is of the JSON type.
     *
     * @return bool
     */
    public function isJson(): bool
    {
        return $this->type === 'json';
    }
}
