<?php

namespace PryTax\Helpers;

use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Class Sql.
 *
 * SQL that MySQL and SQLite write differently (APP-BUILDS-PLAN.md, phase 2): PryTax runs on MySQL on servers and on
 * SQLite on the desktop. On MySQL each expression is exactly what the migrations and queries wrote before, so the
 * existing databases and their generated columns stay as they are. Expressions are raw SQL, not values: never pass
 * user input to them.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Helpers
 */
final class Sql
{
    /**
     * Determine whether the connection is SQLite's.
     *
     * @param string|null $connection The connection's name; the default one by default
     *
     * @return bool
     */
    public static function isSqlite(?string $connection = null): bool
    {
        return DB::connection($connection)->getDriverName() === 'sqlite';
    }

    /**
     * Get the year of a date, as an integer.
     *
     * @param string $date An SQL expression, such as a quoted column
     * @param string|null $connection
     *
     * @return string
     */
    public static function year(string $date, ?string $connection = null): string
    {
        return self::isSqlite($connection) ? "CAST(strftime('%Y', $date) AS INTEGER)" : "YEAR($date)";
    }

    /**
     * Get the quarter of a date, 1 to 4.
     *
     * @param string $date
     * @param string|null $connection
     *
     * @return string
     */
    public static function quarter(string $date, ?string $connection = null): string
    {
        return self::isSqlite($connection) ? "((CAST(strftime('%m', $date) AS INTEGER) + 2) / 3)" : "QUARTER($date)";
    }

    /**
     * Get the month of a date, 1 to 12.
     *
     * @param string $date
     * @param string|null $connection
     *
     * @return string
     */
    public static function month(string $date, ?string $connection = null): string
    {
        return self::isSqlite($connection) ? "CAST(strftime('%m', $date) AS INTEGER)" : "MONTH($date)";
    }

    /**
     * Get one value, when the condition holds, and another otherwise: MySQL's `IF()`.
     *
     * @param string $condition
     * @param string $then
     * @param string $else
     * @param string|null $connection
     *
     * @return string
     */
    public static function when(string $condition, string $then, string $else = 'NULL', ?string $connection = null): string
    {
        return self::isSqlite($connection) ? "CASE WHEN $condition THEN $then ELSE $else END" : "IF($condition, $then, $else)";
    }

    /**
     * Join strings: MySQL's `CONCAT()`.
     *
     * @param array<int, string> $parts SQL expressions, such as quoted columns and string literals
     * @param string|null $connection
     *
     * @return string
     */
    public static function concat(array $parts, ?string $connection = null): string
    {
        return self::isSqlite($connection) ? '(' . implode(' || ', $parts) . ')' : 'CONCAT(' . implode(', ', $parts) . ')';
    }

    /**
     * Determine whether the exception is a violation of the unique index, which MySQL names
     * (`invoices_profile_id_number_unique`) and SQLite lists by its columns (`invoices.profile_id,
     * invoices.active_number`).
     *
     * @param QueryException $exception
     * @param string $index
     * @param string $table
     * @param array<int, string> $columns
     *
     * @return bool
     */
    public static function violatesUnique(QueryException $exception, string $index, string $table, array $columns): bool
    {
        if (!$exception instanceof UniqueConstraintViolationException) {
            return false;
        }

        $listed = implode(', ', array_map(fn (string $column) => "$table.$column", $columns));

        return str_contains($exception->getMessage(), $index) || str_contains($exception->getMessage(), "UNIQUE constraint failed: $listed");
    }

    /**
     * Determine whether the exception is a foreign key that restricts a delete: MySQL's error 1451, SQLite's
     * `FOREIGN KEY constraint failed`.
     *
     * @param QueryException $exception
     *
     * @return bool
     */
    public static function restrictsDelete(QueryException $exception): bool
    {
        return ($exception->errorInfo[1] ?? null) === 1451 || str_contains($exception->getMessage(), 'FOREIGN KEY constraint failed');
    }
}
