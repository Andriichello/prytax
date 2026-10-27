<?php

namespace PryTax\DataLifecycle\Registry;

use InvalidArgumentException;

/**
 * Class Rewrite.
 *
 * An id, which sits inside another value (a JSON document, a key such as `invoices:client:12:2026`), and so isn't
 * remapped like a foreign key, when an archive is imported (backups.md §4.2 "Ids inside values"). It is found by a
 * JSON path (`$.profile.signature_file_id`, `$` for the whole value, an array of ids or one id) or by a pattern
 * with one group of digits, and replaced through the id map of the table `to`. `when` limits it to the rows, whose
 * columns have one of the values (a setting's key).
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Registry
 */
final class Rewrite
{
    /**
     * Rewrite constructor.
     *
     * @param string $column
     * @param string $to Table, whose id map the ids go through.
     * @param string|null $json JSON path of the ids.
     * @param string|null $pattern Pattern with one group of digits, the id.
     * @param array<string, array<int, string>> $when Columns of the rows, to which it applies, with their values.
     */
    public function __construct(
        public readonly string $column,
        public readonly string $to,
        public readonly ?string $json = null,
        public readonly ?string $pattern = null,
        public readonly array $when = [],
    ) {
        if (($json === null) === ($pattern === null)) {
            throw new InvalidArgumentException("A rewrite of $column takes either a JSON path or a pattern.");
        }

        if ($json !== null && !preg_match('/^\$(\.[A-Za-z0-9_]+)*$/', $json)) {
            throw new InvalidArgumentException("The JSON path '$json' of $column isn't one of keys only, such as \$.a.b.");
        }
    }

    /**
     * Determine if the rewrite applies to the row.
     *
     * @param array<string, string|null> $row
     *
     * @return bool
     */
    public function appliesTo(array $row): bool
    {
        foreach ($this->when as $column => $values) {
            if (!in_array($row[$column] ?? null, $values, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get the keys of the JSON path, without its `$`.
     *
     * @return array<int, string>
     */
    public function jsonKeys(): array
    {
        return $this->json === null ? [] : array_values(array_filter(explode('.', substr($this->json, 1))));
    }

    /**
     * Get the rewrite as plain values, for the registry's hash.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'column' => $this->column,
            'to' => $this->to,
            'json' => $this->json,
            'pattern' => $this->pattern,
            'when' => $this->when,
        ], fn (mixed $value) => $value !== null && $value !== []);
    }
}
