<?php

namespace PryTax\Console\Starter;

use Dotenv\Dotenv;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
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
 * MySQL in a container or the one its DB_HOST names; then, if wanted, the first owner and the logs.
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

        if (!is_file($path)) {
            if (!confirm("There's no $file. Make it from .env.docker.example, with a new APP_KEY and database password?")) {
                return Start::FAILURE;
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
            return Start::FAILURE;
        }

        $this->run([...$compose, 'ps']);
        info('PryTax runs at ' . ($settings['APP_URL'] ?? 'http://localhost:8080') . '. ./prytax stop stops it.');

        if (confirm('Create the first owner now? (A new installation has none)', default: false)) {
            $this->run([...$compose, 'exec', 'app', 'php', 'artisan', 'prytax:create-owner']);
        }

        if (confirm('Follow the logs? (Ctrl+C stops following, not PryTax)', default: false)) {
            $this->run([...$compose, 'logs', '-f']);
        }

        return Start::SUCCESS;
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
