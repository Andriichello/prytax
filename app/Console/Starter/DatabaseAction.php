<?php

namespace PryTax\Console\Starter;

use PryTax\Console\Commands\Start;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;
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
        // The desktop app's storage is outside the checkout, which is all the starter's container sees
        $target = select('Which database?', array_filter([
            'web' => ucfirst(Database::web()->label),
            'desktop' => $this->toolchain->inContainer() ? null : 'The desktop app\'s — database/nativephp.sqlite',
            'sqlite' => 'Another SQLite file',
        ]), default: 'web');
        $path = $target === 'sqlite' ? text('Which SQLite file?', default: database_path('database.sqlite'), required: true) : null;
        $path = $path === null || str_starts_with($path, '/') ? $path : base_path($path);

        if ($path !== null && !$this->reachable($path)) {
            return Start::FAILURE;
        }

        $database = match ($target) {
            'desktop' => Database::desktop($this->toolchain),
            'sqlite' => Database::sqlite((string) $path),
            default => Database::web(),
        };

        $task = select("What should be done to $database->label?", [
            'status' => 'Show its migrations',
            'migrate' => 'Run the pending migrations',
            'demo' => 'Start over with the demo data — erases it',
            'owner' => 'Create the first owner of a new installation',
        ]);

        return match ($task) {
            'status' => $this->artisan(['migrate:status'], $database->env),
            'migrate' => $this->migrate($database),
            'demo' => $this->startOver($database, $target),
            'owner' => $this->artisan(['prytax:create-owner'], [...$database->env, ...self::SINGLE_ORGANIZATION]),
        } ? Start::SUCCESS : Start::FAILURE;
    }

    /**
     * Erase the database and seed the demo data, once its name is typed, after a copy of a SQLite file.
     *
     * @param Database $database
     * @param string $target
     *
     * @return bool
     */
    protected function startOver(Database $database, string $target): bool
    {
        $port = $target === 'desktop' ? Listeners::DESKTOP_PORT : ($target === 'web' ? Listeners::webPort() : null);

        if ($port !== null && $this->listeners->isListening($port)) {
            error("The app on $database->label runs: close it first (./prytax stop).");

            return false;
        }

        $name = $database->file === null ? (string) config('database.connections.' . config('database.default') . '.database') : basename($database->file);

        if (text("This erases everything in $database->label. Type «{$name}» to go on") !== $name) {
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
