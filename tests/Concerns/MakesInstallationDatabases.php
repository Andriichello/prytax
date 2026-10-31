<?php

namespace Tests\Concerns;

use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PDO;
use PryTax\Services\Installation\InstallationWork;
use Spatie\Permission\PermissionRegistrar;

/**
 * Trait MakesInstallationDatabases.
 *
 * SQLite files for the tests of full backups: small ones of a few tables, written by hand, and migrated ones, which a
 * test makes the default connection; each is removed after the test, with the backups' work directory.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Concerns
 */
trait MakesInstallationDatabases
{
    /**
     * The files made, which are removed after the test.
     *
     * @var list<string>
     */
    private array $databaseFiles = [];

    /**
     * Remove the files made.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        foreach ($this->databaseFiles as $file) {
            array_map('unlink', glob("$file*") ?: []);
        }

        // The backups made, uploaded and opened
        File::deleteDirectory(InstallationWork::path(''));

        parent::tearDown();
    }

    /**
     * Make a key, as `APP_KEY` holds it.
     *
     * @return string
     */
    protected function makeKey(): string
    {
        return 'base64:' . base64_encode(random_bytes(32));
    }

    /**
     * Encrypt a value with a key, as the `encrypted` cast does.
     *
     * @param string $key
     * @param string $value
     *
     * @return string
     */
    protected function encryptWith(string $key, string $value): string
    {
        return (new Encrypter(base64_decode(Str::after($key, 'base64:')), 'aes-256-cbc'))->encryptString($value);
    }

    /**
     * Make a small database of PryTax's shape: the tables, which tell PryTax's, with profiles, invoices, an audit
     * log and sessions, and the rows given, by table.
     *
     * @param array<string, list<array<string, mixed>>> $rows
     * @param list<string> $migrations
     *
     * @return string
     */
    protected function smallDatabase(array $rows, array $migrations = ['2026_10_20_000000_create_profiles_table']): string
    {
        $file = $this->databaseFiles[] = sys_get_temp_dir() . '/prytax-installation-' . Str::random(8) . '.sqlite';
        $pdo = new PDO("sqlite:$file", options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec(<<<'SQL'
            CREATE TABLE migrations (id INTEGER PRIMARY KEY, migration TEXT, batch INTEGER);
            CREATE TABLE organizations (id INTEGER PRIMARY KEY, name TEXT, deleted_at TEXT, created_at TEXT, updated_at TEXT);
            CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT, last_login_at TEXT, remember_token TEXT, deleted_at TEXT, created_at TEXT, updated_at TEXT);
            CREATE TABLE profiles (id INTEGER PRIMARY KEY, organization_id INTEGER, full_name TEXT, tax_id TEXT, tax_id_hash TEXT, deleted_at TEXT, created_at TEXT, updated_at TEXT);
            CREATE TABLE invoices (id INTEGER PRIMARY KEY, number TEXT, status TEXT, created_at TEXT, updated_at TEXT);
            CREATE TABLE audit_logs (id INTEGER PRIMARY KEY, event TEXT, created_at TEXT);
            CREATE TABLE sessions (id TEXT PRIMARY KEY, payload TEXT);
            SQL);

        foreach ($migrations as $index => $migration) {
            $pdo->prepare('INSERT INTO migrations (id, migration, batch) VALUES (?, ?, 1)')->execute([$index + 1, $migration]);
        }

        foreach ($rows as $table => $records) {
            foreach ($records as $record) {
                $columns = implode(', ', array_keys($record));
                $marks = implode(', ', array_fill(0, count($record), '?'));
                $pdo->prepare("INSERT INTO $table ($columns) VALUES ($marks)")->execute(array_values($record));
            }
        }

        return $file;
    }

    /**
     * Make a migrated database with the roles, and make it the default connection.
     *
     * @param string $connection
     *
     * @return string
     */
    protected function migratedDatabase(string $connection): string
    {
        $file = $this->databaseFiles[] = sys_get_temp_dir() . '/prytax-installation-' . Str::random(8) . '.sqlite';
        touch($file);
        config(["database.connections.$connection" => [...config('database.connections.sqlite'), 'database' => $file]]);
        $this->useDatabase($connection);

        Artisan::call('migrate', ['--database' => $connection, '--force' => true]);
        Artisan::call('db:seed', ['--database' => $connection, '--force' => true]);

        return $file;
    }

    /**
     * Make a connection the default one.
     *
     * @param string $connection
     *
     * @return void
     */
    protected function useDatabase(string $connection): void
    {
        config(['database.default' => $connection]);
        DB::purge($connection);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
