<?php

namespace PryTax\DataLifecycle\Backup\Internal;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use JsonException;
use PryTax\DataLifecycle\Registry\Column;
use PryTax\DataLifecycle\Registry\TableSpec;
use RuntimeException;

/**
 * Class Values.
 *
 * Converts a column's value between the database and the archive (backups.md §2.5, D6): encrypted columns decrypted
 * (JSON ones validated and compact), JSON compact, points in time in UTC as `2026-10-27T02:37:11Z`, everything else as
 * stored (decimals unformatted, booleans 1/0, dates `YYYY-MM-DD`). The database keeps points in time in the app's
 * timezone, as Laravel writes them.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Backup\Internal
 */
final class Values
{
    /**
     * Flags of the JSON, which an archive holds.
     *
     * @var int
     */
    private const int JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    /**
     * Convert a stored value into the archive's.
     *
     * @param TableSpec $table
     * @param Column $column
     * @param mixed $value
     *
     * @return string|null
     * @throws RuntimeException When an encrypted value can't be decrypted, or a JSON one isn't JSON.
     */
    public static function export(TableSpec $table, Column $column, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = (string) $value;
        $decrypted = $table->decrypted();

        if (array_key_exists($column->name, $decrypted)) {
            try {
                $value = Crypt::decryptString($value);
            } catch (DecryptException) {
                throw new RuntimeException("{$table->table()}.{$column->name} can't be decrypted with the app's keys.");
            }

            return $decrypted[$column->name] ? self::compactJson($table, $column, $value) : $value;
        }

        if ($column->isJson()) {
            return self::compactJson($table, $column, $value);
        }

        if ($column->isTimestamp()) {
            return CarbonImmutable::parse($value, config('app.timezone'))->utc()->format('Y-m-d\TH:i:s\Z');
        }

        return $value;
    }

    /**
     * Convert an archive's value into the one to store.
     *
     * @param TableSpec $table
     * @param Column $column
     * @param string|null $value
     *
     * @return string|null
     */
    public static function import(TableSpec $table, Column $column, ?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (array_key_exists($column->name, $table->decrypted())) {
            return Crypt::encryptString($value);
        }

        if ($column->isTimestamp()) {
            return CarbonImmutable::parse($value)->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
        }

        return $value;
    }

    /**
     * Encode a value as the archive's JSON.
     *
     * @param mixed $value
     *
     * @return string
     */
    public static function json(mixed $value): string
    {
        return json_encode($value, self::JSON_FLAGS);
    }

    /**
     * Write a JSON value compact, keeping objects and lists as they are.
     *
     * @param TableSpec $table
     * @param Column $column
     * @param string $value
     *
     * @return string
     */
    private static function compactJson(TableSpec $table, Column $column, string $value): string
    {
        try {
            return self::json(json_decode($value, false, 512, JSON_THROW_ON_ERROR));
        } catch (JsonException) {
            throw new RuntimeException("{$table->table()}.{$column->name} isn't valid JSON.");
        }
    }
}
