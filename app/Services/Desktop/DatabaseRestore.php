<?php

namespace PryTax\Services\Desktop;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Validation\ValidationException;
use PDO;
use Pdo\Sqlite;
use PDOException;

/**
 * Class DatabaseRestore.
 *
 * Restoring the desktop app on a new computer (builds.md §4.6): the old computer's database
 * file and its recovery kit, whose key must decrypt the database's tax IDs. Both are checked before anything is
 * replaced; then the database takes the new installation's place, and the key its key's.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Services\Desktop
 */
class DatabaseRestore
{
    /**
     * The tables, which tell a PryTax database.
     *
     * @var array<int, string>
     */
    protected const array TABLES = ['migrations', 'organizations', 'users', 'profiles'];

    /**
     * DatabaseRestore constructor.
     *
     * @param InstallationKey $key
     * @param string $databasePath The installation's database file
     */
    public function __construct(protected InstallationKey $key, protected string $databasePath) {}

    /**
     * Get the key of a recovery kit's file.
     *
     * @param string $kitPath
     *
     * @return string
     * @throws ValidationException When the file holds no key.
     */
    public function keyOf(string $kitPath): string
    {
        $text = is_file($kitPath) ? (string) file_get_contents($kitPath, length: 64 * 1024) : '';

        if (!preg_match('/base64:[A-Za-z0-9+\/]{43}=/', $text, $match)) {
            throw ValidationException::withMessages(['kit' => [__('The file isn\'t a recovery kit of PryTax.')]]);
        }

        return $match[0];
    }

    /**
     * Check that the file is a PryTax database, which the key decrypts.
     *
     * @param string $sourcePath
     * @param string $key
     *
     * @return void
     * @throws ValidationException When it isn't one, or the key doesn't fit it.
     */
    public function verify(string $sourcePath, string $key): void
    {
        try {
            $db = new Sqlite('sqlite:' . $sourcePath, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, Sqlite::ATTR_OPEN_FLAGS => Sqlite::OPEN_READONLY]);
            $tables = $db->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException) {
            $tables = [];
        }

        if (array_diff(self::TABLES, $tables) !== []) {
            throw ValidationException::withMessages(['database' => [__('The file isn\'t a database of PryTax.')]]);
        }

        $encrypted = $db->query('SELECT tax_id FROM profiles WHERE tax_id IS NOT NULL LIMIT 1')->fetchColumn();

        if ($encrypted === false) {
            return;
        }

        try {
            (new Encrypter(base64_decode(substr($key, 7)), config('app.cipher')))->decryptString((string) $encrypted);
        } catch (DecryptException) {
            throw ValidationException::withMessages(['kit' => [__('The recovery kit\'s key doesn\'t open this database: choose the kit of the same computer.')]]);
        }
    }

    /**
     * Put the database in the installation's place, and its key in place of the installation's. The connections to
     * the database, and its queue worker, must be closed first; the app is relaunched after it.
     *
     * @param string $sourcePath
     * @param string $key
     *
     * @return void
     */
    public function install(string $sourcePath, string $key): void
    {
        $directory = dirname($this->databasePath);
        $temporary = tempnam($directory, 'restore');
        copy($sourcePath, $temporary);

        // The new installation's write-ahead log isn't the restored database's
        foreach (['-wal', '-shm'] as $suffix) {
            if (is_file($this->databasePath . $suffix)) {
                unlink($this->databasePath . $suffix);
            }
        }

        if (PHP_OS_FAMILY === 'Windows' && is_file($this->databasePath)) {
            unlink($this->databasePath);
        }

        rename($temporary, $this->databasePath);

        $this->key->replace($key);
        $this->key->markRecoveryKitSaved('restore');
    }
}
