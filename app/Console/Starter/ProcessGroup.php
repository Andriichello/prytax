<?php

namespace PryTax\Console\Starter;

use Closure;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Terminal;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Throwable;

/**
 * Class ProcessGroup.
 *
 * Processes, which run together in the foreground, such as the web app's server, queue worker and scheduler: each
 * line of theirs prefixed with its name, until Ctrl+C, or until one of those it started with ends, which stops the
 * others too. Meanwhile keys may start or stop more of them, such as a tunnel, whose end stops nothing else.
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
     * The processes, which run, by name: invoked or, in tests, faked; both can `stop()`, which the contract lacks.
     *
     * @var array<string, InvokedProcess>
     */
    protected array $processes = [];

    /**
     * The names of the processes, whose end stops the group: those started before it waits.
     *
     * @var list<string>
     */
    protected array $essential = [];

    /**
     * The colours of the names.
     *
     * @var array<string, string>
     */
    protected array $colours = [];

    /**
     * The width of the names.
     *
     * @var int
     */
    protected int $width = 0;

    /**
     * Whether the group waits for its processes.
     *
     * @var bool
     */
    protected bool $waiting = false;

    /**
     * ProcessGroup constructor.
     *
     * @param Closure(string): void $write Writes a formatted line.
     */
    public function __construct(protected Closure $write) {}

    /**
     * Start a process, whose lines are printed after its name.
     *
     * @param string $name
     * @param list<string> $command
     * @param array<string, string|bool> $env
     * @param string|null $path The working directory, the project's by default.
     * @param (Closure(string): ?string)|null $filter What to print of a line of its output, if anything; blank lines
     *                                                aren't printed.
     *
     * @return void
     */
    public function start(string $name, array $command, array $env, ?string $path = null, ?Closure $filter = null): void
    {
        $this->width = max($this->width, strlen($name));
        $this->colours[$name] ??= self::COLOURS[count($this->colours) % count(self::COLOURS)];

        $this->processes[$name] = Process::path($path ?? base_path())->env($env)->forever()->start($command, function (string $type, string $output) use ($name, $filter) {
            foreach (preg_split('/\R/', rtrim($output)) ?: [] as $line) {
                $line = $filter === null ? $line : $filter($line);

                if ($line !== null && trim($line) !== '') {
                    $this->say($name, OutputFormatter::escape($line));
                }
            }
        });

        if (!$this->waiting) {
            $this->essential[] = $name;
        }
    }

    /**
     * Print a formatted line after a process's name.
     *
     * @param string $name
     * @param string $message
     *
     * @return void
     */
    public function say(string $name, string $message): void
    {
        $this->width = max($this->width, strlen($name));

        ($this->write)(sprintf('<fg=%s>%s</> %s', $this->colours[$name] ?? 'gray', str_pad($name, $this->width), $message));
    }

    /**
     * Determine whether the process runs.
     *
     * @param string $name
     *
     * @return bool
     */
    public function running(string $name): bool
    {
        return isset($this->processes[$name]) && $this->processes[$name]->running();
    }

    /**
     * Stop a process: SIGTERM, then SIGKILL after 5 seconds.
     *
     * @param string $name
     *
     * @return void
     */
    public function stop(string $name): void
    {
        if ($this->running($name)) {
            $this->processes[$name]->stop(5);
        }

        unset($this->processes[$name]);
    }

    /**
     * Wait until Ctrl+C, or until one of the processes started before ends, calling the keys' callbacks as they're
     * pressed meanwhile (on a terminal), then stop the rest. Get whether it ended well: by Ctrl+C, or with the
     * processes, which ended, successful.
     *
     * @param array<string, array{0: string, 1: Closure(): void}> $keys What each key does, and its callback.
     *
     * @return bool
     */
    public function wait(array $keys = []): bool
    {
        $this->waiting = true;
        [$interrupted, $ended] = [false, []];
        $previous = [pcntl_signal_get_handler(SIGINT), pcntl_signal_get_handler(SIGTERM)];
        pcntl_async_signals(true);
        pcntl_signal(SIGINT, function () use (&$interrupted) {
            $interrupted = true;
        });
        pcntl_signal(SIGTERM, function () use (&$interrupted) {
            $interrupted = true;
        });

        $terminal = $keys === [] ? null : $this->listen();

        if ($terminal !== null) {
            $help = collect($keys)->map(fn (array $key, string $press) => "$press to {$key[0]}")->implode(', ');
            ($this->write)("<fg=gray>Press $help; Ctrl+C stops everything.</>");
        }

        try {
            while (!$interrupted && ($ended = $this->ended()) === []) {
                $pressed = $terminal === null ? '' : strtolower((string) fread(STDIN, 16));

                if (isset($keys[$pressed])) {
                    $keys[$pressed][1]();
                }

                usleep(100_000);
            }
        } finally {
            if ($terminal !== null) {
                stream_set_blocking(STDIN, true);
                $terminal->restoreTty();
            }
        }

        $failed = $interrupted ? [] : array_filter($ended, fn (string $name) => $this->processes[$name]->wait()->failed());
        $rest = array_keys(array_filter($this->processes, fn (InvokedProcess $process) => $process->running()));

        if (!$interrupted) {
            $message = 'Ended: ' . implode(', ', $ended) . '.' . ($rest === [] ? '' : ' Stopping the rest.');
            ($this->write)($failed === [] ? "<fg=yellow>$message</>" : "<fg=red>$message</>");
        }

        foreach ($rest as $name) {
            $this->stop($name);
        }

        pcntl_signal(SIGINT, $previous[0]);
        pcntl_signal(SIGTERM, $previous[1]);

        return $interrupted || $failed === [];
    }

    /**
     * Get the processes, which ended, of those the group stops with, and tell those of the others, which ended.
     *
     * @return list<string>
     */
    protected function ended(): array
    {
        $ended = [];

        foreach ($this->processes as $name => $process) {
            if ($process->running()) {
                continue;
            }

            if (in_array($name, $this->essential, true)) {
                $ended[] = $name;

                continue;
            }

            $code = $process->wait()->exitCode();
            $this->say($name, $code === 0 ? 'Ended.' : "<fg=red>Ended, with exit code $code.</>");
            unset($this->processes[$name]);
        }

        return $ended;
    }

    /**
     * Read keys from the terminal without waiting for Enter, and get the terminal, or null without one.
     *
     * @return Terminal|null
     */
    protected function listen(): ?Terminal
    {
        if (!stream_isatty(STDIN)) {
            return null;
        }

        try {
            $terminal = new Terminal;
            // Ctrl+C still interrupts (no -isig)
            $terminal->setTty('-icanon -echo');
        } catch (Throwable) {
            return null;
        }

        stream_set_blocking(STDIN, false);

        return $terminal;
    }
}
