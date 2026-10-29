<?php

namespace PryTax\Helpers;

use Illuminate\Support\Facades\DB;

/**
 * Class CheckConstraint.
 *
 * A CHECK of a table's rows, which the migrations add after creating the table (APP-BUILDS-PLAN.md, phase 2). MySQL
 * adds it to the table, exactly as the migrations did before. SQLite only takes CHECKs inside CREATE TABLE, so there
 * two triggers check the rows instead, after an insert and after an update: they abort the statement when the
 * condition is false, and let it be when it's true or NULL, as a CHECK does.
 *
 * A table that SQLite rebuilds, as Laravel does to change a column, loses its triggers: add them again after it.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Helpers
 */
final class CheckConstraint
{
    /**
     * Add the check to the table.
     *
     * @param string $table
     * @param string $name
     * @param string $condition An SQL condition over the table's columns
     *
     * @return void
     */
    public static function add(string $table, string $name, string $condition): void
    {
        if (!Sql::isSqlite()) {
            DB::statement("ALTER TABLE $table ADD CONSTRAINT $name CHECK ($condition)");

            return;
        }

        foreach (['insert' => 'INSERT', 'update' => 'UPDATE'] as $suffix => $event) {
            DB::statement(
                "CREATE TRIGGER {$name}_$suffix AFTER $event ON $table BEGIN"
                . " SELECT RAISE(ABORT, 'CHECK constraint failed: $name') FROM $table WHERE rowid = NEW.rowid AND NOT ($condition);"
                . ' END'
            );
        }
    }

    /**
     * Drop the check from the table.
     *
     * @param string $table
     * @param string $name
     *
     * @return void
     */
    public static function drop(string $table, string $name): void
    {
        if (!Sql::isSqlite()) {
            DB::statement("ALTER TABLE $table DROP CHECK $name");

            return;
        }

        DB::statement("DROP TRIGGER IF EXISTS {$name}_insert");
        DB::statement("DROP TRIGGER IF EXISTS {$name}_update");
    }
}
