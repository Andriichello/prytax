<?php

namespace PryTax\Console\Starter;

use Dotenv\Dotenv;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Class Listeners.
 *
 * What of PryTax runs on this machine, told by the ports it listens on (`ss`, or `lsof` without it), and stopping it:
 * a port's processes, or the desktop app's Electron, whose closing stops its PHP server and worker with it.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Console\Starter
 */
class Listeners
{
    /**
     * The port of the desktop window's PHP server while developing.
     *
     * @var int
     */
    public const int DESKTOP_PORT = 8100;

    /**
     * The port of the desktop window's DevTools protocol, which lets scripts drive the window.
     *
     * @var int
     */
    public const int DEVTOOLS_PORT = 9333;

    /**
     * The port of the phone's server.
     *
     * @var int
     */
    public const int PHONE_PORT = 8012;

    /**
     * The port of the local API of ngrok's agent, which tells the tunnel's address.
     *
     * @var int
     */
    public const int NGROK_PORT = 4040;

    /**
     * Get PryTax's services, by key: what each is, its port, and the address it's opened at, if any.
     *
     * @return array<string, array{label: string, port: int, url: string|null}>
     */
    public function services(): array
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost';

        return [
            'web' => ['label' => 'Web app', 'port' => self::webPort(), 'url' => "http://$host:" . self::webPort()],
            'vite' => ['label' => 'Vite (hot reload)', 'port' => 5173, 'url' => null],
            'desktop' => ['label' => 'Desktop app', 'port' => self::DESKTOP_PORT, 'url' => null],
            'phone' => ['label' => 'Phone server', 'port' => self::PHONE_PORT, 'url' => null],
            'ngrok' => ['label' => 'ngrok tunnel', 'port' => self::NGROK_PORT, 'url' => null],
            'docker' => ['label' => 'Docker app', 'port' => self::dockerPort(), 'url' => self::dockerSettings()['APP_URL'] ?? 'http://localhost:' . self::dockerPort()],
            'mysql' => ['label' => 'MySQL', 'port' => 3306, 'url' => null],
        ];
    }

    /**
     * Get PryTax's services, which run, by key.
     *
     * @return array<string, array{label: string, port: int, url: string|null}>
     */
    public function running(): array
    {
        $listening = $this->listening();

        return array_filter($this->services(), fn (array $service) => array_key_exists($service['port'], $listening));
    }

    /**
     * Get the web app's port: APP_URL's, or 8010 (8000 belongs to another project on the development machine).
     *
     * @return int
     */
    public static function webPort(): int
    {
        return (int) (parse_url((string) config('app.url'), PHP_URL_PORT) ?: 8010);
    }

    /**
     * Get the Docker app's port on the host: `.env.docker`'s APP_PORT, or compose's 8080.
     *
     * @return int
     */
    public static function dockerPort(): int
    {
        return (int) ((self::dockerSettings()['APP_PORT'] ?? null) ?: 8080);
    }

    /**
     * Get the Docker app's settings: `.env.docker`'s, if it's there.
     *
     * @return array<string, string|null>
     */
    public static function dockerSettings(): array
    {
        $settings = base_path('.env.docker');

        return is_file($settings) ? Dotenv::parse((string) file_get_contents($settings)) : [];
    }

    /**
     * Get the ports listened on, with the ids of their processes when they can be seen (not another user's).
     *
     * @return array<int, list<int>>
     */
    public function listening(): array
    {
        $ss = Process::run(['ss', '-ltnpH']);

        if ($ss->successful()) {
            return self::parseSs($ss->output());
        }

        $lsof = Process::run(['lsof', '-nP', '-iTCP', '-sTCP:LISTEN', '-Fpn']);

        return $lsof->successful() ? self::parseLsof($lsof->output()) : [];
    }

    /**
     * Determine whether the port is listened on.
     *
     * @param int $port
     *
     * @return bool
     */
    public function isListening(int $port): bool
    {
        return array_key_exists($port, $this->listening());
    }

    /**
     * Get the ports and their processes from `ss -ltnpH`: `LISTEN 0 128 127.0.0.1:8100 0.0.0.0:* users:((…,pid=1,…))`.
     *
     * @param string $output
     *
     * @return array<int, list<int>>
     */
    public static function parseSs(string $output): array
    {
        $ports = [];

        foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
            $columns = preg_split('/\s+/', trim($line)) ?: [];

            if (count($columns) < 4) {
                continue;
            }

            $port = (int) Str::afterLast($columns[3], ':');
            preg_match_all('/pid=(\d+)/', $line, $pids);
            $ports[$port] = array_values(array_unique([...$ports[$port] ?? [], ...array_map('intval', $pids[1])]));
        }

        return $ports;
    }

    /**
     * Get the ports and their processes from `lsof -Fpn`: a `p<pid>` line, then an `n<address>:<port>` line a port.
     *
     * @param string $output
     *
     * @return array<int, list<int>>
     */
    public static function parseLsof(string $output): array
    {
        [$ports, $pid] = [[], null];

        foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
            if (str_starts_with($line, 'p')) {
                $pid = (int) substr($line, 1);
            } elseif (str_starts_with($line, 'n') && $pid !== null) {
                $port = (int) Str::afterLast($line, ':');
                $ports[$port] = array_values(array_unique([...$ports[$port] ?? [], $pid]));
            }
        }

        return $ports;
    }

    /**
     * Get the ids of the desktop window's Electron (its main process, not its helpers), which `native:run` started.
     *
     * @return list<int>
     */
    public function desktopProcesses(): array
    {
        $electron = base_path('vendor/nativephp/desktop/resources/electron/node_modules/electron/dist/electron');

        return collect(preg_split('/\R/', trim(Process::run(['ps', '-eo', 'pid=,args='])->output())) ?: [])
            ->map(fn (string $line) => preg_split('/\s+/', trim($line), 2) ?: [])
            ->filter(fn (array $process) => count($process) === 2 && str_starts_with($process[1], "$electron ") && !str_contains($process[1], '--type='))
            ->map(fn (array $process) => (int) $process[0])
            ->values()
            ->all();
    }

    /**
     * Stop the processes with SIGTERM, and get those, which couldn't be (another user's, such as a system MySQL).
     *
     * @param list<int> $pids
     *
     * @return list<int>
     */
    public function stop(array $pids): array
    {
        return array_values(array_filter($pids, fn (int $pid) => $pid !== getmypid() && !posix_kill($pid, SIGTERM)));
    }
}
