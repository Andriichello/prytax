<?php

namespace PryTax\Console\Starter;

use Illuminate\Support\Str;

/**
 * Class DockerSettings.
 *
 * The settings of the web app in Docker, which the starter makes from `.env.docker.example` (builds.md §2.3, §6): read
 * by compose through PRYTAX_ENV_FILE, with a key of their own, and the database chosen: SQLite (the example's, a file in
 * the storage volume), MySQL in a container (with a password of its own), or an external MySQL, whose host and
 * credentials the owner fills in.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Console\Starter
 */
final class DockerSettings
{
    /**
     * SQLite: a file in the storage volume.
     *
     * @var string
     */
    public const string SQLITE = 'sqlite';

    /**
     * MySQL in a container, of compose's `mysql` profile.
     *
     * @var string
     */
    public const string MYSQL = 'mysql';

    /**
     * A MySQL outside compose, which DB_HOST names.
     *
     * @var string
     */
    public const string EXTERNAL = 'external';

    /**
     * Get the databases, which can be chosen, with what each is.
     *
     * @return array<string, string>
     */
    public static function databases(): array
    {
        return [
            self::SQLITE => 'SQLite — a file in the storage volume, no database server',
            self::MYSQL => 'MySQL in a container',
            self::EXTERNAL => 'An external MySQL — fill in its host and credentials afterwards',
        ];
    }

    /**
     * Make the settings from the example's text, for the file compose finds them in and the database.
     *
     * @param string $example
     * @param string $file As compose finds it, relative to the project.
     * @param string $database One of `databases()`.
     *
     * @return string
     */
    public static function make(string $example, string $file, string $database): string
    {
        $settings = (string) preg_replace('/^# PRYTAX_ENV_FILE=.*$/m', "PRYTAX_ENV_FILE=$file", $example);
        $settings = (string) preg_replace('/^APP_KEY=$/m', 'APP_KEY=base64:' . base64_encode(random_bytes(32)), $settings);

        if ($database === self::SQLITE) {
            return $settings;
        }

        // MySQL's lines in, SQLite's out, with a password of its own
        $settings = (string) preg_replace('/^# (DB_(?:CONNECTION|HOST|PORT|DATABASE|USERNAME|PASSWORD)=.*)$/m', '$1', $settings);
        $settings = (string) preg_replace('/^(DB_CONNECTION=sqlite|DB_DATABASE=\S+\.sqlite)$/m', '# $1', $settings);
        $settings = (string) preg_replace('/^DB_PASSWORD=change-me$/m', 'DB_PASSWORD=' . Str::random(24), $settings);

        return $database === self::EXTERNAL
            ? (string) preg_replace('/^DB_HOST=mysql$/m', 'DB_HOST=host.docker.internal', $settings)
            : $settings;
    }
}
