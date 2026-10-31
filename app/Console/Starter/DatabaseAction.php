<?php

namespace PryTax\Console\Starter;

use PryTax\Console\Commands\Start;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

/**
 * Class DatabaseAction.
 *
 * A database's tasks (`./prytax db`), of the web app's, the desktop app's or a SQLite file: the migrations' status,
 * running the pending ones, starting over with the demo data, and creating the first owner. Starting over erases the
 * database, so it asks for its name to be typed, keeps a copy of a SQLite file, and waits for the app on it to close.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Console\Starter
 */
class DatabaseAction extends Action
{
    /**
     * Perform the action, and get its exit code.
     *
     * @return int
     */
    protected function handle(): int
    {
        $answers = $this->ask([
            // The desktop app's storage is outside the checkout, which is all the starter's container sees
            'target' => fn () => $this->choose('Which database?', array_filter([
                'web' => ucfirst(Database::web()->label),
                'desktop' => $this->toolchain->inContainer() ? null : 'The desktop app\'s — database/nativephp.sqlite',
                'sqlite' => 'Another SQLite file',
            ]), 'web'),
            'path' => fn (array $answers) => $answers['target'] === 'sqlite' ? $this->typed('Which SQLite file?', database_path('database.sqlite')) : null,
            'database' => fn (array $answers) => $this->databaseOf((string) $answers['target'], $answers['path']) ?? self::STOP,
            'task' => fn (array $answers) => $this->choose("What should be done to {$answers['database']->label}?", [
                'status' => 'Show its migrations',
                'migrate' => 'Run the pending migrations',
                'demo' => 'Start over with the demo data — erases it',
                'owner' => 'Create the first owner of a new installation',
            ]),
            'answer' => fn (array $answers) => match ($answers['task']) {
                'migrate' => $this->migrationQuestion($answers['database']),
                'demo' => $this->eraseQuestion($answers['database'], (string) $answers['target']),
                default => null,
            },
        ]);

        if ($answers === null) {
            return $this->ended();
        }

        $database = $answers['database'];

        return match ($answers['task']) {
            'status' => $this->artisan(['migrate:status'], $database->env),
            'migrate' => $this->migrateWith($database, $answers['answer']),
            'demo' => $this->startOver($database, (string) $answers['answer']),
            'owner' => $this->artisan(['prytax:create-owner'], [...$database->env, ...self::SINGLE_ORGANIZATION]),
        } ? Start::SUCCESS : Start::FAILURE;
    }

    /**
     * Get the database chosen, or null, told, of a SQLite file the starter can't reach.
     *
     * @param string $target
     * @param string|null $path
     *
     * @return Database|null
     */
    protected function databaseOf(string $target, ?string $path): ?Database
    {
        if ($target === 'sqlite') {
            $path = str_starts_with((string) $path, '/') ? (string) $path : base_path((string) $path);

            return $this->reachable($path) ? Database::sqlite($path) : null;
        }

        return $target === 'desktop' ? Database::desktop($this->toolchain) : Database::web();
    }

    /**
     * Ask for the database's name to be typed, before it's erased, unless its app runs; get what was typed, `BACK`,
     * or `STOP`, told.
     *
     * @param Database $database
     * @param string $target
     *
     * @return string
     */
    protected function eraseQuestion(Database $database, string $target): string
    {
        $port = $target === 'desktop' ? Listeners::DESKTOP_PORT : ($target === 'web' ? Listeners::webPort() : null);

        if ($port !== null && $this->listeners->isListening($port)) {
            error("The app on $database->label runs: close it first (./prytax stop).");

            return self::STOP;
        }

        return $this->typed("This erases everything in $database->label. Type «{$this->nameOf($database)}» to go on");
    }

    /**
     * Get the name, which erasing a database asks for: a SQLite file's, or the database's.
     *
     * @param Database $database
     *
     * @return string
     */
    protected function nameOf(Database $database): string
    {
        return $database->file === null ? (string) config('database.connections.' . config('database.default') . '.database') : basename($database->file);
    }

    /**
     * Erase the database and seed the demo data, when its name was typed, after a copy of a SQLite file.
     *
     * @param Database $database
     * @param string $typed
     *
     * @return bool
     */
    protected function startOver(Database $database, string $typed): bool
    {
        if ($typed !== $this->nameOf($database)) {
            warning('Nothing was erased.');

            return false;
        }

        if ($copy = $database->backup('demo')) {
            info("Copied it to $copy first.");
        }

        if ($database->isNew()) {
            touch((string) $database->file);
        }

        $done = $this->artisan(['migrate:fresh', '--force', '--seed'], $database->env)
            && $this->artisan(['db:seed', '--class=Database\Seeders\DemoSeeder', '--force'], $database->env);

        if ($done) {
            info('Sign in as olena@example.com / password.');
        }

        return $done;
    }
}
