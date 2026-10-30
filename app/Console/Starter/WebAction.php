<?php

namespace PryTax\Console\Starter;

use PryTax\Console\Commands\Start;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * Class WebAction.
 *
 * The web app on this machine (`./prytax web`): PHP's server on APP_URL's port, the queue worker (backups, bundles,
 * period exports) and the scheduler, and Vite's hot reload or the built SPA, on `.env`'s MySQL or a SQLite file,
 * in the foreground until Ctrl+C.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Console\Starter
 */
class WebAction extends Action
{
    /**
     * Perform the action, and get its exit code.
     *
     * @return int
     */
    protected function handle(): int
    {
        $port = (int) ($this->start->option('port') ?: Listeners::webPort());
        $database = $this->database();

        if ($database === null || !$this->free($port, 'The web app') || !$this->migrate($database)) {
            return Start::FAILURE;
        }

        $vite = $this->start->option('vite') ?: confirm('Use Vite\'s hot reload? (No: the built SPA)', default: false);

        if (!$vite && !$this->ensureBuild()) {
            return Start::FAILURE;
        }

        $php = $this->toolchain->php();
        $commands = [
            'web' => [$php, 'artisan', 'serve', '--host=127.0.0.1', "--port=$port", '--no-reload'],
            'queue' => [$php, 'artisan', 'queue:work', '--queue=backups,retention,default'],
            'schedule' => [$php, 'artisan', 'schedule:work'],
        ];

        if ($vite) {
            $commands['vite'] = [base_path('node_modules/.bin/vite')];
        }

        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost';
        $env = $database->env;

        // Another port than APP_URL's signs in only when it's stateful too
        if ($port !== Listeners::webPort()) {
            $env += ['APP_URL' => "http://$host:$port", 'SANCTUM_STATEFUL_DOMAINS' => "$host:$port,127.0.0.1:$port"];
        }

        info("PryTax runs at http://$host:$port on $database->label. Ctrl+C stops it.");

        $interrupted = app(ProcessGroup::class)->run($commands, $this->toolchain->env($env), function (string $line) {
            $this->start->getOutput()->writeln($line);
        });

        return $interrupted ? Start::SUCCESS : Start::FAILURE;
    }

    /**
     * Get the database, which `--db` (and `--sqlite`) or the user chose, or null of an unknown one.
     *
     * @return Database|null
     */
    protected function database(): ?Database
    {
        $mysql = (string) config('database.connections.mysql.database');
        $choice = $this->start->option('db') ?? select('Which database?', [
            'mysql' => "MySQL — .env's «{$mysql}»",
            'sqlite' => 'A SQLite file — no database server needed',
        ], default: config('database.default') === 'sqlite' ? 'sqlite' : 'mysql');

        if ($choice === 'mysql') {
            return new Database("MySQL «{$mysql}»", ['DB_CONNECTION' => 'mysql'], null);
        }

        if ($choice !== 'sqlite') {
            error("--db is mysql or sqlite, not $choice.");

            return null;
        }

        $path = $this->start->option('sqlite') ?? text('Which SQLite file?', default: database_path('database.sqlite'), required: true);

        return Database::sqlite(str_starts_with($path, '/') ? $path : base_path($path));
    }
}
