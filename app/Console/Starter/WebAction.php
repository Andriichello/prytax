<?php

namespace PryTax\Console\Starter;

use PryTax\Console\Commands\Start;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;

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
            return match (true) {
                $this->end === 'back' => self::WENT_BACK,
                $this->start->isInteractive() => Start::SUCCESS,
                default => Start::FAILURE,
            };
        }

        $mysql = (string) config('database.connections.mysql.database');
        $answers = $this->ask([
            'db' => fn () => $this->start->option('db') ?? $this->choose('Which database?', [
                'mysql' => "MySQL — .env's «{$mysql}»",
                'sqlite' => 'A SQLite file — no database server needed',
            ], config('database.default') === 'sqlite' ? 'sqlite' : 'mysql'),
            'sqlite' => fn (array $answers) => $answers['db'] !== 'sqlite'
                ? null
                : $this->start->option('sqlite') ?? $this->typed('Which SQLite file?', database_path('database.sqlite')),
            'database' => fn (array $answers) => $this->databaseOf((string) $answers['db'], $answers['sqlite']) ?? self::STOP,
            'migrate' => fn (array $answers) => $this->migrationQuestion($answers['database']),
            'vite' => fn () => $this->start->option('vite') ?: $this->decide('How should the SPA be served?', 'Vite\'s hot reload — changes show at once', 'The built SPA', false),
            'build' => fn (array $answers) => $answers['vite'] ? null : $this->buildQuestion(),
        ]);

        if ($answers === null) {
            return $this->ended();
        }

        [$database, $vite] = [$answers['database'], $answers['vite'] === true];
        $newDatabase = $database->isNew();

        if (!$this->migrateWith($database, $answers['migrate']) || (!$vite && !$this->buildWith($answers['build']))) {
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

        $this->remember($database->file === null ? 'web-here-mysql' : 'web-here-sqlite', $database->file, $newDatabase);

        // Vite's hot reload serves the SPA from this machine only, so a phone couldn't load it; no ngrok in the container
        $keys = $vite || $this->toolchain->inContainer() ? [] : ['n' => ['open or close an ngrok tunnel for a phone', function () use ($group, $port, $database) {
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

        if ($choice === 'back') {
            $this->end = 'back';
        }

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
     * Get the database chosen (`--db`, `--sqlite`, or the answers), or null, told, of an unknown one or a file the
     * starter can't reach.
     *
     * @param string $choice
     * @param string|null $path
     *
     * @return Database|null
     */
    protected function databaseOf(string $choice, ?string $path): ?Database
    {
        if ($choice === 'mysql') {
            return new Database('MySQL «' . config('database.connections.mysql.database') . '»', ['DB_CONNECTION' => 'mysql'], null);
        }

        if ($choice !== 'sqlite') {
            error("--db is mysql or sqlite, not $choice.");

            return null;
        }

        $path = str_starts_with((string) $path, '/') ? (string) $path : base_path((string) $path);

        return $this->reachable($path) ? Database::sqlite($path) : null;
    }
}
