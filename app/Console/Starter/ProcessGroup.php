<?php

namespace PryTax\Console\Starter;

use Closure;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Class ProcessGroup.
 *
 * Processes, which run together in the foreground, such as the web app's server, queue worker and scheduler: each
 * line of theirs prefixed with its name, until Ctrl+C, or until one of them ends, which stops the others too.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Console\Starter
 */
class ProcessGroup
{
    /**
     * The colours of the names, in turn.
     *
     * @var list<string>
     */
    protected const array COLOURS = ['cyan', 'magenta', 'yellow', 'green', 'blue'];

    /**
     * Run the processes until Ctrl+C (true) or until one of them ends (false), then stop the others.
     *
     * @param array<string, list<string>> $commands By name.
     * @param array<string, string|bool> $env
     * @param Closure(string): void $write Writes a formatted line.
     * @param array<string, string> $paths The working directories of those, which don't run in the project's.
     *
     * @return bool
     */
    public function run(array $commands, array $env, Closure $write, array $paths = []): bool
    {
        $names = array_keys($commands);
        $width = max(array_map('strlen', $names));
        $colours = array_combine($names, array_map(fn (int $i) => self::COLOURS[$i % count(self::COLOURS)], array_keys($names)));

        $pool = Process::pool(function (Pool $pool) use ($commands, $env, $paths) {
            foreach ($commands as $name => $command) {
                $pool->as($name)->path($paths[$name] ?? base_path())->env($env)->forever()->command($command);
            }
        })->start(function (string $type, string $output, string $name) use ($write, $colours, $width) {
            foreach (preg_split('/\R/', rtrim($output)) ?: [] as $line) {
                $write(sprintf('<fg=%s>%s</> %s', $colours[$name], str_pad($name, $width), OutputFormatter::escape($line)));
            }
        });

        $interrupted = false;
        $previous = [pcntl_signal_get_handler(SIGINT), pcntl_signal_get_handler(SIGTERM)];
        pcntl_async_signals(true);
        pcntl_signal(SIGINT, function () use (&$interrupted) {
            $interrupted = true;
        });
        pcntl_signal(SIGTERM, function () use (&$interrupted) {
            $interrupted = true;
        });

        while (!$interrupted && $pool->running()->count() === count($commands)) {
            usleep(100_000);
        }

        if (!$interrupted) {
            $ended = collect(iterator_to_array($pool))->reject(fn ($process) => $process->running())->keys()->implode(', ');
            $write("<fg=red>Ended: $ended. Stopping the rest.</>");
        }

        // SIGTERM, then SIGKILL to what still runs after 5 seconds
        $pool->stop(5);
        pcntl_signal(SIGINT, $previous[0]);
        pcntl_signal(SIGTERM, $previous[1]);

        return $interrupted;
    }
}
