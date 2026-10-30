<?php

namespace PryTax\Console\Starter;

use Illuminate\Support\Facades\Process;
use PDO;

/**
 * Class Database.
 *
 * A database the starter runs PryTax or its artisan commands on: the web app's (`.env`), the desktop app's
 * (`database/nativephp.sqlite`, with its storage) or any SQLite file, as the environment variables that point a child
 * process at it. A SQLite file can be copied beside itself before it's changed.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Console\Starter
 */
final readonly class Database
{
    /**
     * Database constructor.
     *
     * @param string $label What it is, for the messages.
     * @param array<string, string> $env The variables of a child process.
     * @param string|null $file The SQLite file, or null of a server's database.
     */
    public function __construct(public string $label, public array $env, public ?string $file) {}

    /**
     * Get the web app's database, as `.env` configures it.
     *
     * @return self
     */
    public static function web(): self
    {
        $connection = (string) config('database.default');
        $name = (string) config("database.connections.$connection.database");

        return $connection === 'sqlite'
            ? self::sqlite($name, "the web app's SQLite file")
            : new self("the web app's $connection database «{$name}»", [], null);
    }

    /**
     * Get a SQLite file's database.
     *
     * @param string $path
     * @param string|null $label
     * @param array<string, string> $env
     *
     * @return self
     */
    public static function sqlite(string $path, ?string $label = null, array $env = []): self
    {
        return new self($label ?? "the SQLite file $path", ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $path, ...$env], $path);
    }

    /**
     * Get the desktop app's database while developing, with its storage, where seeders put their files, and the file
     * cache, as the `native:*` commands need outside the window (builds.md §4.10).
     *
     * @param Toolchain $toolchain
     *
     * @return self
     */
    public static function desktop(Toolchain $toolchain): self
    {
        return self::sqlite(database_path('nativephp.sqlite'), "the desktop app's database", [
            'CACHE_STORE' => 'file',
            'LARAVEL_STORAGE_PATH' => $toolchain->desktopStoragePath(),
        ]);
    }

    /**
     * Determine whether the database has no tables yet: a missing or empty SQLite file.
     *
     * @return bool
     */
    public function isNew(): bool
    {
        return $this->file !== null && (!is_file($this->file) || filesize($this->file) === 0);
    }

    /**
     * Get the migrations, which haven't run on it, or null when it can't be told (the server is down).
     *
     * @param Toolchain $toolchain
     *
     * @return list<string>|null
     */
    public function pending(Toolchain $toolchain): ?array
    {
        $result = Process::path(base_path())
            ->env($toolchain->env($this->env))
            ->run([$toolchain->php(), 'artisan', 'migrate:status', '--pending', '--no-ansi']);

        if ($result->failed()) {
            return null;
        }

        preg_match_all('/^\s*(\d{4}_\d{2}_\d{2}_\d{6}_\w+)\s.*Pending/m', $result->output(), $matches);

        return $matches[1];
    }

    /**
     * Copy the SQLite file beside itself, `{file}.before-{reason}-{time}`, whole with what its write-ahead log holds,
     * and get the copy's path; null of a server's database or a new file.
     *
     * @param string $reason
     *
     * @return string|null
     */
    public function backup(string $reason): ?string
    {
        if ($this->file === null || $this->isNew()) {
            return null;
        }

        $copy = $this->file . '.before-' . $reason . '-' . now()->format('Ymd-His');
        $pdo = new PDO('sqlite:' . $this->file);
        $pdo->exec('VACUUM INTO ' . $pdo->quote($copy));

        return $copy;
    }
}
