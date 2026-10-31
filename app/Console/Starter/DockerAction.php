<?php

namespace PryTax\Console\Starter;

use Dotenv\Dotenv;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PryTax\Console\Commands\Start;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;

/**
 * Class DockerAction.
 *
 * The web app in Docker (`./prytax docker`, builds.md §2): `docker compose up` with the development checkout's
 * `.env.docker` (made from `.env.docker.example` when it's missing, with its own key and database password), with
 * MySQL in a container or the one its DB_HOST names. Once it runs (or when it ran already), a menu: an ngrok tunnel
 * for a phone, the first owner, the logs, or stopping it.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Console\Starter
 */
class DockerAction extends Action
{
    /**
     * Perform the action, and get its exit code.
     *
     * @return int
     */
    protected function handle(): int
    {
        if (Process::run(['docker', 'compose', 'version'])->failed()) {
            error('Docker with its compose plugin isn\'t installed.');

            return Start::FAILURE;
        }

        $file = (string) ($this->start->option('env-file') ?: '.env.docker');
        $path = str_starts_with($file, '/') ? $file : base_path($file);
        $settings = is_file($path) ? Dotenv::parse((string) file_get_contents($path)) : [];
        $port = (int) (($settings['APP_PORT'] ?? null) ?: 8080);

        if (!$this->listeners->isListening($port) && !$this->up($path, $file)) {
            return Start::FAILURE;
        }

        if (!$this->start->isInteractive()) {
            info('PryTax runs at ' . (Dotenv::parse((string) file_get_contents($path))['APP_URL'] ?? "http://localhost:$port") . '. ./prytax stop docker stops it.');

            return Start::SUCCESS;
        }

        $this->menu($path, $port);

        return Start::SUCCESS;
    }

    /**
     * Start the containers, making the settings first when they're missing, and get whether they started.
     *
     * @param string $path
     * @param string $file As compose finds it, relative to the project.
     *
     * @return bool
     */
    protected function up(string $path, string $file): bool
    {
        if (!is_file($path)) {
            if (!confirm("There's no $file. Make it from .env.docker.example, with a new APP_KEY and database password?")) {
                return false;
            }

            $this->makeSettings($path, $file);
            note("Made $file: check APP_URL and the database in it.");
        }

        $settings = Dotenv::parse((string) file_get_contents($path));
        $mysql = $this->start->option('mysql') ?: confirm(
            "Run MySQL in a container? (No: the MySQL, which $file's DB_HOST names)",
            default: ($settings['DB_HOST'] ?? null) === 'mysql',
        );
        $compose = ['docker', 'compose', '--env-file', $path, ...($mysql ? ['--profile', 'mysql'] : [])];

        if (!$this->run([...$compose, 'up', '-d', '--build'])) {
            return false;
        }

        $this->run([...$compose, 'ps']);

        return true;
    }

    /**
     * Offer what's done with the containers, which run, until Back, Quit or stopping them.
     *
     * @param string $path
     * @param int $port
     *
     * @return void
     */
    protected function menu(string $path, int $port): void
    {
        $settings = Dotenv::parse((string) file_get_contents($path));
        $compose = ['docker', 'compose', '--env-file', $path];

        do {
            $choice = $this->start->menu('PryTax runs in Docker at ' . ($settings['APP_URL'] ?? "http://localhost:$port") . '.', [
                'tunnel' => 'Open an ngrok tunnel for a phone',
                'owner' => 'Create the first owner (a new installation has none)',
                'logs' => 'Follow the logs (Ctrl+C stops following, not PryTax)',
                'stop' => 'Stop it',
            ], hint: 'It keeps running when you go back or quit.');

            match ($choice) {
                'tunnel' => $this->tunnel($compose, $settings, $port),
                'owner' => $this->run([...$compose, 'exec', 'app', 'php', 'artisan', 'prytax:create-owner']),
                'logs' => $this->run([...$compose, 'logs', '-f']),
                'stop' => $this->run([...$compose, '--profile', 'mysql', 'down']),
                default => null,
            };
        } while (in_array($choice, ['tunnel', 'owner', 'logs'], true));
    }

    /**
     * Open an ngrok tunnel to the app until Ctrl+C. Meanwhile its container is made again with the tunnel's address
     * signing in (its own host stateful, Docker's private networks proxies to trust), then as its settings say.
     *
     * @param list<string> $compose
     * @param array<string, string|null> $settings
     * @param int $port
     *
     * @return void
     */
    protected function tunnel(array $compose, array $settings, int $port): void
    {
        $tunnel = app(Tunnel::class);

        if (!$tunnel->isInstalled()) {
            error('ngrok isn\'t installed.');
            note(Tunnel::INSTALL . '.');

            return;
        }

        $stateful = array_filter([$settings['SANCTUM_STATEFUL_DOMAINS'] ?? null, Sanctum::$currentRequestHostPlaceholder]);
        $override = storage_path('framework/prytax-tunnel.compose.yaml');
        file_put_contents($override, implode(PHP_EOL, [
            '# Made by ./prytax while an ngrok tunnel is open, then removed',
            'services:',
            '  app:',
            '    environment:',
            '      SANCTUM_STATEFUL_DOMAINS: ' . json_encode(implode(',', $stateful)),
            '      TRUSTED_PROXIES: PRIVATE_SUBNETS',
            '',
        ]));
        // Only the app's container, which serves the requests; without the MySQL profile, so a MySQL of its own stays
        $app = [...$compose, '-f', base_path('compose.yaml')];

        try {
            info('Making the app\'s container again, so that the tunnel\'s address signs in…');

            if (!$this->run([...$app, '-f', $override, 'up', '-d', 'app'])) {
                return;
            }

            info('Opening an ngrok tunnel. Ctrl+C closes it; Docker keeps running.');
            $group = $this->group();
            $tunnel->open($group, "127.0.0.1:$port", 'on the phone, and sign in with an account of the Docker app');
            $group->wait();
        } finally {
            info('Making the app\'s container again, as its settings say…');
            $this->run([...$app, 'up', '-d', 'app']);
            @unlink($override);
        }
    }

    /**
     * Make the settings from `.env.docker.example`: compose reads them through PRYTAX_ENV_FILE, with a key of their
     * own and a database password.
     *
     * @param string $path
     * @param string $file As compose finds it, relative to the project.
     *
     * @return void
     */
    protected function makeSettings(string $path, string $file): void
    {
        $settings = (string) file_get_contents(base_path('.env.docker.example'));
        $settings = preg_replace('/^# PRYTAX_ENV_FILE=.*$/m', "PRYTAX_ENV_FILE=$file", $settings);
        $settings = preg_replace('/^APP_KEY=$/m', 'APP_KEY=base64:' . base64_encode(random_bytes(32)), (string) $settings);
        $settings = preg_replace('/^DB_PASSWORD=change-me$/m', 'DB_PASSWORD=' . Str::random(24), (string) $settings);

        file_put_contents($path, (string) $settings);
        chmod($path, 0600);
    }
}
