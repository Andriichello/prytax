<?php

namespace PryTax\Helpers;

/**
 * Class Desktop.
 *
 * The desktop app (APP-BUILDS-PLAN.md, phase 3): PryTax run by NativePHP in a window of its own, against a SQLite
 * database in the user's application data, or in `database/nativephp.sqlite` while developing (`native:run`).
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Helpers
 */
final class Desktop
{
    /**
     * Determine whether PryTax runs as the desktop app.
     *
     * @return bool
     */
    public static function isRunning(): bool
    {
        return (bool) config('nativephp-internal.running');
    }

    /**
     * Get the path of the desktop's database file, as NativePHP chooses it, or null outside the desktop app.
     *
     * @return string|null
     */
    public static function databasePath(): ?string
    {
        if (!self::isRunning()) {
            return null;
        }

        return config('app.debug') ? database_path('nativephp.sqlite') : config('nativephp-internal.database_path');
    }
}
