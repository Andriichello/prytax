<?php

namespace PryTax\Services\Installation;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PDO;
use RuntimeException;
use ZipArchive;

/**
 * Class InstallationBackup.
 *
 * A full backup of the installation (builds.md §4.11), to restore it on another computer: a snapshot of its SQLite
 * database (every organization), its key, its documents and its in-app backups, in one zip encrypted with a password
 * (AES-256), as the key opens everything in it. Its manifest tells when it was made, when the data changed last, and
 * what it holds.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Services\Installation
 */
class InstallationBackup
{
    /**
     * The format of the manifest.
     *
     * @var string
     */
    public const string FORMAT = 'prytax-installation';

    /**
     * The version of the format.
     *
     * @var int
     */
    public const int FORMAT_VERSION = 1;

    /**
     * InstallationBackup constructor.
     *
     * @param InstallationComparison $comparison
     */
    public function __construct(protected InstallationComparison $comparison) {}

    /**
     * Determine whether the installation can be backed up whole: its database is a SQLite file.
     *
     * @return bool
     */
    public static function isSupported(): bool
    {
        $connection = DB::connection();

        return $connection->getDriverName() === 'sqlite' && is_file((string) $connection->getConfig('database'));
    }

    /**
     * Get the name, under which a backup made now is saved.
     *
     * @return string
     */
    public static function fileName(): string
    {
        return 'prytax-full-backup_' . now()->format('Y-m-d_Hi') . '.zip';
    }

    /**
     * Write a full backup of the installation into a file of the work directory, and get its path.
     *
     * @param string $password
     *
     * @return string
     */
    public function write(string $password): string
    {
        $directory = InstallationWork::directory('backups/' . Str::random(16));
        $database = "$directory/database.sqlite";
        $this->snapshot($database);

        $snapshot = InstallationComparison::open($database);
        $documents = $this->files((string) config('api-core.files.disk'), trim((string) config('api-core.files.directory'), '/'));
        $archives = array_filter(
            $this->files((string) config('data-lifecycle.backups.disk'), ''),
            fn (string $path) => !str_starts_with($path, 'tmp/'),
            ARRAY_FILTER_USE_KEY,
        );
        $manifest = [
            'format' => self::FORMAT,
            'format_version' => self::FORMAT_VERSION,
            'made_at' => now()->toIso8601String(),
            'app_version' => (string) config('nativephp.version'),
            ...$this->comparison->describe($snapshot),
            'documents' => ['count' => count($documents), 'bytes' => array_sum(array_map('filesize', $documents))],
            'archives' => ['count' => count($archives), 'bytes' => array_sum(array_map('filesize', $archives))],
        ];
        $snapshot = null;

        $path = "$directory.zip";
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('manifest.json', (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $zip->addFromString('key', (string) config('app.key'));
        $zip->addFile($database, 'database.sqlite');

        foreach ($documents as $relative => $file) {
            $zip->addFile($file, "documents/$relative");
        }

        foreach ($archives as $relative => $file) {
            $zip->addFile($file, "archives/$relative");
        }

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $zip->setEncryptionIndex($index, ZipArchive::EM_AES_256, $password);
        }

        if (!$zip->close()) {
            throw new RuntimeException("The full backup $path couldn't be written.");
        }

        File::deleteDirectory($directory);

        return $path;
    }

    /**
     * Copy the database, whole and consistent, as it is now (with what its write-ahead log holds).
     *
     * @param string $target
     *
     * @return void
     */
    protected function snapshot(string $target): void
    {
        $source = new PDO('sqlite:' . DB::connection()->getConfig('database'), options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $source->exec('VACUUM INTO ' . $source->quote($target));
    }

    /**
     * Get the files of a local disk under a directory: their local paths, by their paths on the disk.
     *
     * @param string $disk
     * @param string $directory
     *
     * @return array<string, string>
     */
    protected function files(string $disk, string $directory): array
    {
        $storage = Storage::disk($disk);
        $files = [];

        foreach ($storage->allFiles($directory) as $file) {
            $files[$file] = $storage->path($file);
        }

        return $files;
    }
}
