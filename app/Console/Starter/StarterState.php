<?php

namespace PryTax\Console\Starter;

use Carbon\CarbonImmutable;
use Dotenv\Dotenv;
use Illuminate\Support\Str;
use Throwable;

/**
 * Class StarterState.
 *
 * What the starters (`./prytax`, `./start`) remember between runs (builds.md §6), in a git-ignored file of dotenv lines,
 * which `./start` writes too: the last run, which started an app, with its choice (the platform, the place, the
 * database), and, per choice, when it was set up on this machine and when its database was created (the date its
 * overview told last). A choice's dates start over with its new settings or a new database.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Console\Starter
 */
final class StarterState
{
    /**
     * The file, relative to the project, where `./start` finds it too.
     *
     * @var string
     */
    public const string FILE = 'storage/framework/prytax-starter.env';

    /**
     * The choices, which are remembered, with what each is.
     *
     * @var array<string, string>
     */
    public const array CHOICES = [
        'desktop' => 'Desktop',
        'web-here-mysql' => 'Web · Here · MySQL',
        'web-here-sqlite' => 'Web · Here · SQLite',
        'web-docker-sqlite' => 'Web · In Docker · SQLite',
        'web-docker-mysql' => 'Web · In Docker · MySQL',
    ];

    /**
     * The file's path.
     *
     * @var string
     */
    protected string $path;

    /**
     * StarterState constructor.
     *
     * @param string|null $path The file, `FILE` of the project by default.
     */
    public function __construct(?string $path = null)
    {
        $this->path = $path ?? base_path(self::FILE);
    }

    /**
     * Get the last run, which started an app: its choice, what it is, its SQLite file (of the web app here on SQLite),
     * when it ran and by which starter, and the choice's dates; or null without one.
     *
     * @return array{choice: string, label: string, sqlite: string|null, run_at: string|null, run_by: string|null, set_up_at: string|null, database_created_at: string|null}|null
     */
    public function last(): ?array
    {
        $values = $this->values();
        $choice = $values['LAST_CHOICE'] ?? null;

        if (!array_key_exists((string) $choice, self::CHOICES)) {
            return null;
        }

        $sqlite = $choice === 'web-here-sqlite' ? ($values['LAST_SQLITE'] ?? null) : null;

        return [
            'choice' => $choice,
            'label' => self::CHOICES[$choice] . ($sqlite === null ? '' : ' (' . Str::after($sqlite, base_path() . '/') . ')'),
            'sqlite' => $sqlite,
            'run_at' => $values['LAST_RUN_AT'] ?? null,
            'run_by' => $values['LAST_RUN_BY'] ?? null,
            'set_up_at' => $values[self::key($choice, 'SET_UP_AT')] ?? null,
            'database_created_at' => $values[self::key($choice, 'DATABASE_CREATED_AT')] ?? null,
        ];
    }

    /**
     * Remember a run of `./prytax`, which started the choice's app now: the choice, its SQLite file, when it was set
     * up (now, unless it was before; another SQLite file is set up anew) and its database's date.
     *
     * @param string $choice One of `CHOICES`.
     * @param string|null $sqlite The SQLite file of the web app here on SQLite.
     * @param string|null $databaseCreatedAt
     *
     * @return void
     */
    public function remember(string $choice, ?string $sqlite = null, ?string $databaseCreatedAt = null): void
    {
        $values = $this->values();

        if ($choice === 'web-here-sqlite' && ($values['WEB_HERE_SQLITE_FILE'] ?? null) !== $sqlite) {
            $values = $this->without($values, $choice);
            $values['WEB_HERE_SQLITE_FILE'] = $sqlite;
        }

        $now = CarbonImmutable::now()->toIso8601String();
        $values = [
            ...$values,
            'LAST_RUN_AT' => $now,
            'LAST_RUN_BY' => 'prytax',
            'LAST_CHOICE' => $choice,
            'LAST_SQLITE' => $sqlite,
            self::key($choice, 'SET_UP_AT') => $values[self::key($choice, 'SET_UP_AT')] ?? $now,
            self::key($choice, 'DATABASE_CREATED_AT') => $databaseCreatedAt ?? $values[self::key($choice, 'DATABASE_CREATED_AT')] ?? null,
        ];

        $this->write($values);
    }

    /**
     * Keep the date the choice's database told it was created, as its overview read it.
     *
     * @param string $choice
     * @param string|null $databaseCreatedAt
     *
     * @return void
     */
    public function rememberDatabaseCreated(string $choice, ?string $databaseCreatedAt): void
    {
        $values = $this->values();

        if ($databaseCreatedAt !== null && ($values[self::key($choice, 'DATABASE_CREATED_AT')] ?? null) !== $databaseCreatedAt) {
            $this->write([...$values, self::key($choice, 'DATABASE_CREATED_AT') => $databaseCreatedAt]);
        }
    }

    /**
     * Forget the choice's dates: its settings or its database are new, so it's set up anew.
     *
     * @param string $choice
     *
     * @return void
     */
    public function forget(string $choice): void
    {
        $values = $this->values();

        if ($values !== []) {
            $this->write($this->without($values, $choice));
        }
    }

    /**
     * Get the values without the choice's dates.
     *
     * @param array<string, string|null> $values
     * @param string $choice
     *
     * @return array<string, string|null>
     */
    protected function without(array $values, string $choice): array
    {
        unset($values[self::key($choice, 'SET_UP_AT')], $values[self::key($choice, 'DATABASE_CREATED_AT')]);

        return $values;
    }

    /**
     * Get the file's values, by key.
     *
     * @return array<string, string|null>
     */
    protected function values(): array
    {
        if (!is_file($this->path)) {
            return [];
        }

        try {
            return Dotenv::parse((string) file_get_contents($this->path));
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Write the values, in place of the file's (through a file beside it, so that a reader never sees half).
     *
     * @param array<string, string|null> $values
     *
     * @return void
     */
    protected function write(array $values): void
    {
        $lines = ['# What ./prytax and ./start remember (builds.md §6): the last run, which started an app, and when each choice was set up'];

        foreach (array_filter($values, fn (?string $value) => $value !== null && $value !== '') as $key => $value) {
            $lines[] = "$key=" . (preg_match('/^[\w.:+\/-]+$/', $value) ? $value : '"' . addcslashes($value, '"\\') . '"');
        }

        if (!is_dir(dirname($this->path))) {
            mkdir(dirname($this->path), 0755, true);
        }

        file_put_contents("$this->path.tmp", implode(PHP_EOL, $lines) . PHP_EOL);
        rename("$this->path.tmp", $this->path);
    }

    /**
     * Get a choice's key, such as `WEB_DOCKER_SQLITE_SET_UP_AT`.
     *
     * @param string $choice
     * @param string $suffix
     *
     * @return string
     */
    protected static function key(string $choice, string $suffix): string
    {
        return strtoupper(str_replace('-', '_', $choice)) . "_$suffix";
    }
}
