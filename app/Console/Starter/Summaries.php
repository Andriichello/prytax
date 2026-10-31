<?php

namespace PryTax\Console\Starter;

use Illuminate\Support\Facades\Process;

/**
 * Class Summaries.
 *
 * The overview (`prytax:summary --json`) of the app a choice of the starter starts (builds.md §6), read from its own
 * database: artisan with its database's settings, as `Database` has them; Docker's from its container, while it runs.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Console\Starter
 */
class Summaries
{
    /**
     * Summaries constructor.
     *
     * @param Toolchain $toolchain
     * @param Listeners $listeners
     */
    public function __construct(protected Toolchain $toolchain, protected Listeners $listeners) {}

    /**
     * Get the overview of the choice's app (`InstallationSummary`), or null when it can't be read: a database not made
     * yet, its server down, Docker not running.
     *
     * @param string $choice One of `StarterState::CHOICES`.
     * @param string|null $sqlite The SQLite file of the web app here on SQLite.
     *
     * @return array<string, mixed>|null
     */
    public function of(string $choice, ?string $sqlite = null): ?array
    {
        // The local app (./start), whose settings name its own compose project
        if ($choice === 'local') {
            return $this->toolchain->inContainer() || !$this->listeners->isListening(Listeners::localPort())
                ? null
                : $this->read(['docker', 'compose', '--env-file', base_path(Listeners::LOCAL_SETTINGS), 'exec', '-T', 'app', 'php', 'artisan', 'prytax:summary', '--json'], []);
        }

        if (str_starts_with($choice, 'web-docker')) {
            if ($this->toolchain->inContainer() || !$this->listeners->isListening(Listeners::dockerPort())) {
                return null;
            }

            $file = is_file(base_path('.env.docker')) ? base_path('.env.docker') : base_path('.env');

            return $this->read(['docker', 'compose', '--env-file', $file, 'exec', '-T', 'app', 'php', 'artisan', 'prytax:summary', '--json'], []);
        }

        $database = match ($choice) {
            'desktop' => Database::desktop($this->toolchain),
            'web-here-sqlite' => $sqlite === null ? null : Database::sqlite($sqlite),
            default => new Database('MySQL', ['DB_CONNECTION' => 'mysql'], null),
        };

        if ($database === null || $database->isNew()) {
            return null;
        }

        return $this->read([$this->toolchain->php(), 'artisan', 'prytax:summary', '--json', '--no-ansi'], $database->env);
    }

    /**
     * Run the command, and get the overview it prints, or null when it fails or prints something else.
     *
     * @param list<string> $command
     * @param array<string, string> $env
     *
     * @return array<string, mixed>|null
     */
    protected function read(array $command, array $env): ?array
    {
        $result = Process::path(base_path())->env($this->toolchain->env($env))->timeout(60)->run($command);
        $summary = $result->successful() ? json_decode(trim($result->output()), true) : null;

        return is_array($summary) && isset($summary['database'], $summary['organizations']) ? $summary : null;
    }
}
