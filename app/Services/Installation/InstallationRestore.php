<?php

namespace PryTax\Services\Installation;

use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PDO;
use PryTax\Models\Profile;

/**
 * Class InstallationRestore.
 *
 * Restoring a full backup in place of this installation (builds.md §4.11): its database takes this one's place with
 * its encrypted values encrypted again with this installation's key (which stays, with its recovery kit), the tax IDs'
 * hashes computed again, and the other computer's sessions, caches and queued jobs emptied; an older one is migrated
 * forward. Its documents and in-app backups take the place of these. The database is prepared on a copy first, so a
 * failure there leaves everything as it was.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Services\Installation
 */
class InstallationRestore
{
    /**
     * Put the backup in place of this installation. Other processes on the database (a queue worker) must be stopped
     * first; this one's connection is closed here.
     *
     * @param InstallationArchive $archive
     *
     * @return void
     */
    public function install(InstallationArchive $archive): void
    {
        $connection = DB::connection();
        $name = $connection->getName();
        $database = (string) $connection->getConfig('database');

        $work = (string) tempnam(dirname($database), 'restore');
        copy($archive->databasePath(), $work);
        $this->prepare($work, $archive->key);

        DB::purge($name);

        // This installation's write-ahead log isn't the restored database's
        foreach (['-wal', '-shm', '-journal'] as $suffix) {
            if (is_file($database . $suffix)) {
                unlink($database . $suffix);
            }
        }

        if (PHP_OS_FAMILY === 'Windows' && is_file($database)) {
            unlink($database);
        }

        rename($work, $database);

        $this->replace((string) config('api-core.files.disk'), trim((string) config('api-core.files.directory'), '/'), $archive->documentsPath());
        $this->replace((string) config('data-lifecycle.backups.disk'), '', $archive->archivesPath());

        // An older backup is brought up to this app's schema, and its roles to this app's permissions
        Artisan::call('migrate', ['--force' => true, '--database' => $name]);
        Artisan::call('db:seed', ['--force' => true, '--database' => $name]);
    }

    /**
     * Prepare a copy of the backup's database for this installation: its encrypted values encrypted again with this
     * installation's key, the tax IDs' hashes computed again with it, and another computer's sessions, caches and
     * queued jobs emptied.
     *
     * @param string $path
     * @param string $key The key, which encrypted it.
     *
     * @return void
     */
    public function prepare(string $path, string $key): void
    {
        $db = InstallationComparison::open($path, readOnly: false);
        $tables = $db->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
        $current = (string) config('app.key');
        $db->beginTransaction();

        if ($key !== $current) {
            [$from, $to] = [$this->encrypter($key), $this->encrypter($current)];

            foreach (array_intersect_key(InstallationTables::ENCRYPTED, array_flip($tables)) as $table => $columns) {
                // An older backup's table may lack a column, which a later migration adds
                $present = $db->query(sprintf('SELECT name FROM pragma_table_info(\'%s\')', $table))->fetchAll(PDO::FETCH_COLUMN);

                foreach (array_intersect($columns, $present) as $column) {
                    $update = $db->prepare(sprintf('UPDATE "%s" SET "%s" = ? WHERE id = ?', $table, $column));
                    $rows = $db->query(sprintf('SELECT id, "%s" FROM "%s" WHERE "%s" IS NOT NULL', $column, $table, $column))->fetchAll(PDO::FETCH_KEY_PAIR);

                    foreach ($rows as $id => $value) {
                        $update->execute([$to->encryptString($from->decryptString((string) $value)), $id]);
                    }
                }
            }

            // The hash of a tax ID is keyed with the installation's key
            if (in_array('profiles', $tables, true)) {
                $update = $db->prepare('UPDATE profiles SET tax_id_hash = ? WHERE id = ?');

                foreach ($db->query('SELECT id, tax_id FROM profiles WHERE tax_id IS NOT NULL')->fetchAll(PDO::FETCH_KEY_PAIR) as $id => $taxId) {
                    $update->execute([Profile::hashTaxId($to->decryptString((string) $taxId)), $id]);
                }
            }
        }

        foreach (array_intersect(InstallationTables::CLEARED, $tables) as $table) {
            $db->exec(sprintf('DELETE FROM "%s"', $table));
        }

        $db->commit();
    }

    /**
     * Put the backup's files in place of a directory of a local disk (the whole disk, but its temporary files, for
     * `''`).
     *
     * @param string $disk
     * @param string $directory
     * @param string $source The backup's files, by their paths on the disk.
     *
     * @return void
     */
    protected function replace(string $disk, string $directory, string $source): void
    {
        $storage = Storage::disk($disk);

        if ($directory === '') {
            $storage->delete(array_filter($storage->allFiles(), fn (string $path) => !str_starts_with($path, 'tmp/')));
        } else {
            $storage->deleteDirectory($directory);
        }

        if (!is_dir($source)) {
            return;
        }

        foreach (File::allFiles($source) as $file) {
            $stream = fopen($file->getPathname(), 'rb');
            $storage->writeStream(str_replace('\\', '/', $file->getRelativePathname()), $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * Get an encrypter of a key (`base64:…`).
     *
     * @param string $key
     *
     * @return Encrypter
     */
    protected function encrypter(string $key): Encrypter
    {
        return new Encrypter(base64_decode(Str::after($key, 'base64:')), (string) config('app.cipher'));
    }
}
