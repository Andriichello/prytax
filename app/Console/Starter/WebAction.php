<?php

namespace PryTax\Console\Starter;

use PryTax\Console\Commands\Start;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * Class WebAction.
 *
 * The web app on this machine (`./prytax web`): PHP's server on APP_URL's port, the queue worker (backups, bundles,
 * period exports) and the scheduler, and Vite's hot reload or the built SPA, on `.env`'s MySQL or a SQLite file,
 * in the foreground until Ctrl+C, with `n` opening an ngrok tunnel to it for a phone. When it runs already, it offers
 * to restart or stop it.
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

        if ($this->listeners->isListening($port) && !$this->restarted($port)) {
            return $this->start->isInteractive() ? Start::SUCCESS : Start::FAILURE;
        }

        $database = $this->database();

        if ($database === null || !$this->migrate($database)) {
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
        // A tunnel may open later, so its address signs in too, and so does another port than APP_URL's
        $env = $this->toolchain->env([
            ...$database->env,
            ...$this->tunnelled(),
            ...($port === Listeners::webPort() ? [] : ['APP_URL' => "http://$host:$port"]),
            ...self::SINGLE_ORGANIZATION,
        ]);

        info("PryTax runs at http://$host:$port on $database->label. Ctrl+C stops it.");
        $group = $this->group();

        foreach ($commands as $name => $command) {
            $group->start($name, $command, $env);
        }

        // Vite's hot reload serves the SPA from this machine only, so a phone couldn't load it
        $keys = $vite ? [] : ['n' => ['open or close an ngrok tunnel for a phone', function () use ($group, $port, $database) {
            app(Tunnel::class)->toggle($group, "127.0.0.1:$port", "on the phone, and sign in with an account of $database->label");
        }]];

        return $group->wait($keys) ? Start::SUCCESS : Start::FAILURE;
    }

    /**
     * Offer to restart or stop the web app, which runs on the port already, and get whether it's to start again,
     * stopped.
     *
     * @param int $port
     *
     * @return bool
     */
    protected function restarted(int $port): bool
    {
        if (!$this->start->isInteractive()) {
            return $this->free($port, 'The web app');
        }

        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost';
        $choice = $this->start->menu("The web app runs already, at http://$host:$port.", [
            'restart' => 'Restart it here',
            'stop' => 'Stop it',
        ]);

        if (!in_array($choice, ['restart', 'stop'], true) || !$this->stopService('web') || $choice === 'stop') {
            return false;
        }

        for ($attempt = 0; $attempt < 50 && $this->listeners->isListening($port); $attempt++) {
            usleep(200_000);
        }

        if ($this->listeners->isListening($port)) {
            error("Port $port is still in use.");
            note('./prytax status shows what runs, and stops it.');

            return false;
        }

        return true;
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
