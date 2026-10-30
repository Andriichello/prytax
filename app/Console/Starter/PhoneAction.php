<?php

namespace PryTax\Console\Starter;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use PryTax\Console\Commands\Start;
use Throwable;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\select;

/**
 * Class PhoneAction.
 *
 * PryTax on a phone (`./prytax phone`): PHP's server on 8012 behind an ngrok tunnel, on the desktop app's data or the
 * web app's, with the tunnel's address as APP_URL, its host stateful, files for sessions and cache, and registration
 * off, so the address can't create organizations. Until Ctrl+C, which closes the tunnel too.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Console\Starter
 */
class PhoneAction extends Action
{
    /**
     * Perform the action, and get its exit code.
     *
     * @return int
     */
    protected function handle(): int
    {
        if (!$this->toolchain->has('ngrok')) {
            error('ngrok isn\'t installed.');
            note('snap install ngrok, then ngrok config add-authtoken <token>, from a free account at ngrok.com.');

            return Start::FAILURE;
        }

        if (!$this->free(Listeners::PHONE_PORT, 'The phone server') || !$this->free(Listeners::NGROK_PORT, 'ngrok')) {
            return Start::FAILURE;
        }

        $source = $this->start->option('source') ?? select('Whose data should the phone see?', [
            'desktop' => 'The desktop app\'s — database/nativephp.sqlite and its files',
            'web' => 'The web app\'s — .env\'s database',
        ], default: 'desktop');
        $database = $source === 'web' ? Database::web() : Database::desktop($this->toolchain);

        if (!$this->migrate($database) || !$this->ensureBuild()) {
            return Start::FAILURE;
        }

        $ngrok = Process::forever()->env($this->toolchain->env())->start(['ngrok', 'http', (string) Listeners::PHONE_PORT, '--log=stdout']);

        try {
            return $this->serve($database, $ngrok->running() ? $this->tunnel() : null);
        } finally {
            $ngrok->stop(5);
        }
    }

    /**
     * Serve PryTax at the tunnel's address until Ctrl+C, and get the exit code.
     *
     * @param Database $database
     * @param string|null $url
     *
     * @return int
     */
    protected function serve(Database $database, ?string $url): int
    {
        if ($url === null) {
            error('ngrok opened no tunnel. Is its authtoken set? ngrok config add-authtoken <token>');

            return Start::FAILURE;
        }

        $host = (string) parse_url($url, PHP_URL_HOST);
        $env = $this->toolchain->env([
            ...$database->env,
            'APP_URL' => $url,
            'ASSET_URL' => $url,
            'SANCTUM_STATEFUL_DOMAINS' => $host,
            'TRUSTED_PROXIES' => '*',
            'SESSION_DRIVER' => 'file',
            'CACHE_STORE' => 'file',
            'QUEUE_CONNECTION' => 'sync',
            'PRYTAX_REGISTRATION' => 'false',
            'PHP_CLI_SERVER_WORKERS' => '4',
        ]);
        // PHP's own server, not `artisan serve`, whose server process wouldn't see LARAVEL_STORAGE_PATH; it serves
        // from the working directory, so from public/
        $server = [
            $this->toolchain->php(), '-d', 'variables_order=EGPCS', '-S', '127.0.0.1:' . Listeners::PHONE_PORT,
            '-t', public_path(), base_path('vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php'),
        ];

        info("Open $url on the phone, and sign in with an account of $database->label. Ctrl+C stops it.");

        $interrupted = app(ProcessGroup::class)->run(['phone' => $server], $env, function (string $line) {
            $this->start->getOutput()->writeln($line);
        }, ['phone' => public_path()]);

        return $interrupted ? Start::SUCCESS : Start::FAILURE;
    }

    /**
     * Get the tunnel's public address from ngrok's local API, waiting up to 20 seconds for it, or null.
     *
     * @return string|null
     */
    protected function tunnel(): ?string
    {
        for ($attempt = 0; $attempt < 40; $attempt++) {
            try {
                $tunnels = Http::timeout(1)->get('http://127.0.0.1:' . Listeners::NGROK_PORT . '/api/tunnels')->json('tunnels', []);
                $url = collect($tunnels)->pluck('public_url')->first(fn (?string $url) => str_starts_with((string) $url, 'https://'));

                if ($url !== null) {
                    return $url;
                }
            } catch (Throwable) {
                // The agent isn't up yet
            }

            usleep(500_000);
        }

        return null;
    }
}
