<?php

namespace PryTax\DataLifecycle\Backup\Internal;

use RuntimeException;

/**
 * Class Csv.
 *
 * The archives' CSV (backups.md §2.5): RFC 4180 with commas, `"` quoting and `\r\n` rows, UTF-8 with a BOM. NULL is
 * an empty unquoted field, an empty string `""`, which PHP's own `fputcsv()` and `str_getcsv()` don't tell apart, so
 * both directions are written here.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Backup\Internal
 */
final class Csv
{
    /**
     * Byte order mark of UTF-8, which Excel needs to read Cyrillic.
     *
     * @var string
     */
    public const string BOM = "\xEF\xBB\xBF";

    /**
     * Format a row of fields: NULL empty and unquoted, a string quoted, when it is empty or holds a comma, a quote or
     * a line break.
     *
     * @param array<int, string|null> $fields
     *
     * @return string
     */
    public static function line(array $fields): string
    {
        $cells = array_map(function (?string $field) {
            if ($field === null) {
                return '';
            }

            if ($field === '' || strpbrk($field, ",\"\r\n") !== false) {
                return '"' . str_replace('"', '""', $field) . '"';
            }

            return $field;
        }, $fields);

        return implode(',', $cells) . "\r\n";
    }

    /**
     * Parse a CSV of the archive into its rows, with NULL for the empty unquoted fields.
     *
     * @param string $csv
     *
     * @return array<int, array<int, string|null>>
     * @throws RuntimeException When a quoted field isn't closed.
     */
    public static function parse(string $csv): array
    {
        if (str_starts_with($csv, self::BOM)) {
            $csv = substr($csv, 3);
        }

        $rows = [];
        $row = [];
        $field = '';
        $quoted = false;
        $length = strlen($csv);
        $i = 0;

        if ($length === 0) {
            return [];
        }

        while ($i < $length) {
            $char = $csv[$i];

            if ($char === '"' && $field === '' && !$quoted) {
                // A quoted field: up to the quote, which isn't doubled
                $end = $i + 1;
                $value = '';

                while (true) {
                    $next = strpos($csv, '"', $end);

                    if ($next === false) {
                        throw new RuntimeException('A quoted field of the CSV isn\'t closed.');
                    }

                    $value .= substr($csv, $end, $next - $end);

                    if (($csv[$next + 1] ?? '') === '"') {
                        $value .= '"';
                        $end = $next + 2;

                        continue;
                    }

                    $i = $next + 1;

                    break;
                }

                $field = $value;
                $quoted = true;

                continue;
            }

            if ($char === ',') {
                $row[] = $quoted ? $field : ($field === '' ? null : $field);
                $field = '';
                $quoted = false;
                $i++;

                continue;
            }

            if ($char === "\r" || $char === "\n") {
                $row[] = $quoted ? $field : ($field === '' ? null : $field);
                $rows[] = $row;
                $row = [];
                $field = '';
                $quoted = false;
                $i += ($char === "\r" && ($csv[$i + 1] ?? '') === "\n") ? 2 : 1;

                continue;
            }

            $field .= $char;
            $i++;
        }

        if ($field !== '' || $quoted || $row !== []) {
            $row[] = $quoted ? $field : ($field === '' ? null : $field);
            $rows[] = $row;
        }

        return $rows;
    }
}
