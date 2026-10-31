<?php

namespace PryTax\Services\Installation;

use Illuminate\Validation\ValidationException;
use ZipArchive;

/**
 * Class InstallationArchive.
 *
 * A full backup of an installation, opened with its password and extracted into a work directory (builds.md §4.11):
 * its manifest, its key, its database, its documents and its in-app backups.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Services\Installation
 */
final class InstallationArchive
{
    /**
     * InstallationArchive constructor.
     *
     * @param string $directory
     * @param array<string, mixed> $manifest
     * @param string $key The key of the installation, which it was made of.
     */
    public function __construct(public readonly string $directory, public readonly array $manifest, public readonly string $key) {}

    /**
     * Open a full backup with its password, and extract it into the directory.
     *
     * @param string $path
     * @param string $password
     * @param string $directory
     *
     * @return self
     * @throws ValidationException When it isn't one, the password doesn't open it, or it's damaged.
     */
    public static function extract(string $path, string $password, string $directory): self
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true || $zip->statName('manifest.json') === false) {
            throw ValidationException::withMessages(['archive' => [__('The file isn\'t a full backup of PryTax.')]]);
        }

        try {
            $zip->setPassword($password);
            $json = $zip->getFromName('manifest.json');

            if ($json === false) {
                throw ValidationException::withMessages(['password' => [__('The password doesn\'t open the backup.')]]);
            }

            $manifest = json_decode($json, true);

            if (!is_array($manifest) || ($manifest['format'] ?? null) !== InstallationBackup::FORMAT) {
                throw ValidationException::withMessages(['archive' => [__('The file isn\'t a full backup of PryTax.')]]);
            }

            if ((int) ($manifest['format_version'] ?? 0) > InstallationBackup::FORMAT_VERSION) {
                throw ValidationException::withMessages(['archive' => [__('The backup was made by a newer PryTax: update it here first.')]]);
            }

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = (string) $zip->getNameIndex($index);

                // Nothing may land outside the directory
                if (str_starts_with($name, '/') || str_contains($name, '\\') || in_array('..', explode('/', $name), true)) {
                    throw ValidationException::withMessages(['archive' => [__('The backup is damaged.')]]);
                }
            }

            if (!$zip->extractTo($directory)) {
                throw ValidationException::withMessages(['archive' => [__('The backup is damaged.')]]);
            }
        } finally {
            $zip->close();
        }

        return self::at($directory) ?? throw ValidationException::withMessages(['archive' => [__('The backup is damaged.')]]);
    }

    /**
     * Get the backup extracted into the directory, or null when it holds none.
     *
     * @param string $directory
     *
     * @return self|null
     */
    public static function at(string $directory): ?self
    {
        $manifest = is_file("$directory/manifest.json") ? json_decode((string) file_get_contents("$directory/manifest.json"), true) : null;
        $key = is_file("$directory/key") ? trim((string) file_get_contents("$directory/key")) : '';

        if (!is_array($manifest) || !preg_match('/^base64:[A-Za-z0-9+\/]{43}=$/', $key) || !is_file("$directory/database.sqlite")) {
            return null;
        }

        return new self($directory, $manifest, $key);
    }

    /**
     * Get the path of its database.
     *
     * @return string
     */
    public function databasePath(): string
    {
        return "$this->directory/database.sqlite";
    }

    /**
     * Get the directory of its documents, by their paths on the files' disk.
     *
     * @return string
     */
    public function documentsPath(): string
    {
        return "$this->directory/documents";
    }

    /**
     * Get the directory of its in-app backups, by their paths on the backups' disk.
     *
     * @return string
     */
    public function archivesPath(): string
    {
        return "$this->directory/archives";
    }
}
