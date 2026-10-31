<?php

namespace PryTax\Console\Starter;

use PryTax\Console\Commands\Start;

use function Laravel\Prompts\error;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

/**
 * Class StatusAction.
 *
 * What of PryTax runs (`./prytax status`), by its ports, and stopping it (`./prytax stop`, which asks, or names what
 * to stop: `./prytax stop web vite`, `--all`): the web app, Vite, the phone server and ngrok by their processes, the
 * desktop app by closing its Electron, and Docker's by `docker compose down`. The system's MySQL is only shown.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Console\Starter
 */
class StatusAction extends Action
{
    /**
     * Perform the action, and get its exit code.
     *
     * @return int
     */
    protected function handle(): int
    {
        $listening = $this->listeners->listening();
        [$rows, $running] = [[], []];

        foreach ($this->listeners->services() as $key => ['label' => $label, 'port' => $port]) {
            $pids = $listening[$port] ?? null;
            $rows[] = [$label, (string) $port, $pids === null ? '—' : 'running', implode(', ', $pids ?? [])];

            // Docker's apps can't be stopped from the starter's container, which has no Docker
            if ($pids !== null && $key !== 'mysql' && !(in_array($key, ['docker', 'local'], true) && $this->toolchain->inContainer())) {
                $running[$key] = "$label (port $port)";
            }
        }

        table(['Service', 'Port', 'State', 'Processes'], $rows);

        if ($running === []) {
            return Start::SUCCESS;
        }

        $chosen = $this->chosen($running);

        if ($chosen === null) {
            return $this->end === null ? Start::FAILURE : $this->ended();
        }

        foreach ($chosen as $key) {
            $this->stopService($key);
        }

        return Start::SUCCESS;
    }

    /**
     * Get what to stop of what runs: what's named, everything with `--all`, or what the user picks; null when it can't
     * be asked.
     *
     * @param array<string, string> $running
     *
     * @return list<string>|null
     */
    protected function chosen(array $running): ?array
    {
        $stopping = $this->start->argument('action') === 'stop';
        $named = $this->start->argument('what');

        if ($named !== []) {
            if ($this->toolchain->inContainer() && array_intersect(['docker', 'local'], $named) !== []) {
                warning('Docker\'s apps can\'t be stopped from the starter\'s container: ./start\'s menu stops the local one.');
                $named = array_values(array_diff($named, ['docker', 'local']));
            }

            foreach (array_diff($named, array_keys($running)) as $idle) {
                warning("Not running: $idle.");
            }

            return array_values(array_intersect(array_keys($running), $named));
        }

        if ($stopping && $this->start->option('all')) {
            return array_keys($running);
        }

        if (!$this->start->isInteractive()) {
            if ($stopping) {
                error('Name what to stop (' . implode(' ', array_keys($running)) . '), or stop everything with --all.');
            }

            return $stopping ? null : [];
        }

        $answers = $this->ask(['stop' => fn () => $this->choose($stopping ? 'Stop which?' : 'Stop any of them?', [
            ...($stopping ? [] : ['none' => 'None — leave them running']),
            ...$running,
            'all' => 'All of them',
        ], $stopping ? 'all' : 'none')]);

        return match ($answers['stop'] ?? null) {
            null => null,
            'none' => [],
            'all' => array_keys($running),
            default => [$answers['stop']],
        };
    }
}
