<?php

namespace PryTax\Mcp;

use Illuminate\Support\Facades\Facade;
use PryTax\Services\Desktop\InstallationKey;

/**
 * Class DatabaseKey.
 *
 * A SQLite database, which keeps its own key beside it (the desktop app's, `database/nativephp.sqlite.key`, builds.md
 * §4.4), is read with that key, not `.env`'s: its tax IDs, IBANs and invoice snapshots are encrypted with it. Claude's
 * server (builds.md §7) and the commands, which read such a database outside its window, apply it before anything is
 * decrypted; the key stays in its file, never in Claude's config.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Mcp
 */
final class DatabaseKey
{
    /**
     * Make the default database's own key the app's, if it keeps one beside it.
     *
     * @return void
     */
    public static function apply(): void
    {
        $name = (string) config('database.default');
        $driver = config("database.connections.$name.driver");
        $path = (string) config("database.connections.$name.database");

        if ($driver !== 'sqlite' || $path === '' || $path === ':memory:') {
            return;
        }

        $key = (new InstallationKey($path))->stored();

        if ($key === null || $key === config('app.key')) {
            return;
        }

        config(['app.key' => $key]);
        // The encrypter is made from the key once; the encrypted casts reach it through the facade
        app()->forgetInstance('encrypter');
        Facade::clearResolvedInstance('encrypter');
    }
}
