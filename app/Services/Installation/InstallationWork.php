<?php

namespace PryTax\Services\Installation;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\ParallelTesting;

/**
 * Class InstallationWork.
 *
 * Where full backups are made, uploaded and opened (`storage/framework/installation/`): outside the disks, which a full
 * backup carries, and removed after a day, when they weren't taken or restored; each process of a parallel test run
 * has its own (`installation_test_<token>/`).
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Services\Installation
 */
final class InstallationWork
{
    /**
     * The hours, after which what's left is removed.
     *
     * @var int
     */
    public const int HOURS = 24;

    /**
     * Get the path of an entry of the work directory.
     *
     * @param string $name Such as `uploads/<id>.zip`.
     *
     * @return string
     */
    public static function path(string $name): string
    {
        $root = storage_path('framework/installation');

        // A process of a parallel test run works in a directory of its own, as Laravel's fake disks do: a test removes
        // the directory after it, which would pull it from under another process's test
        if ($token = ParallelTesting::token()) {
            $root .= "_test_$token";
        }

        return "$root/$name";
    }

    /**
     * Get a directory of the work, made when missing, after removing what's older than a day.
     *
     * @param string $name Such as `restores/<token>`.
     *
     * @return string
     */
    public static function directory(string $name): string
    {
        self::prune();

        $path = self::path($name);
        File::ensureDirectoryExists($path, 0700);

        return $path;
    }

    /**
     * Remove what's older than a day: backups made, which weren't taken, uploads and opened backups.
     *
     * @return void
     */
    public static function prune(): void
    {
        foreach (glob(self::path('*/*')) ?: [] as $entry) {
            if (filemtime($entry) < time() - self::HOURS * 3600) {
                is_dir($entry) ? File::deleteDirectory($entry) : unlink($entry);
            }
        }
    }
}
