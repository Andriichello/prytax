<?php

namespace PryTax\Console\Starter;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use PryTax\Console\Commands\Start;
use Throwable;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\warning;

/**
 * Class McpAction.
 *
 * Tools → Claude (builds.md §7): how Claude Code or Claude Desktop connects to PryTax's data, for each Claude
 * integration, which an owner turned on in Налаштування → Інтеграції (`prytax:claude-connection` with that data's
 * settings, or in Docker's container): the data of the app started last first (the web app here on its SQLite file),
 * the desktop app's, `.env`'s, Docker's while it runs, or another SQLite file; the `claude mcp add …` command and Claude
 * Desktop's JSON; then whether the server answers with it (the handshake, the tools, the ФОП it sees), which the page's
 * status then shows as the starter's connection.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Console\Starter
 */
class McpAction extends Action
{
    /**
     * Perform the action, and get its exit code.
     *
     * @return int
     */
    protected function handle(): int
    {
        $sources = $this->sources();
        $answers = $this->ask([
            'source' => fn () => $this->choose('Whose data should Claude read?', array_map(fn (array $item) => $item['label'], $sources), array_key_first($sources)),
            'path' => fn (array $answers) => $answers['source'] !== 'sqlite' ? null : $this->typed('Which SQLite file?', database_path('database.sqlite')),
        ]);

        if ($answers === null) {
            return $this->ended();
        }

        $source = (string) $answers['source'];

        if (in_array($source, ['docker', 'local'], true)) {
            $label = $source === 'local' ? 'your local app' : 'the Docker app';
            $connections = $this->connectionsOf(
                $this->dockerCompose($source === 'local', ['exec', '-T', 'app', 'php', 'artisan', 'prytax:claude-connection', '--json', '--no-ansi', '--folder=' . base_path()]),
                [],
            );
        } else {
            $database = $sources[$source]['database'] ?? $this->otherSqlite((string) $answers['path']);

            if ($database === null) {
                return Start::FAILURE;
            }

            if ($database->isNew()) {
                error(ucfirst($database->label) . ' has no data yet: start it, and turn Claude on in Settings → Integrations.');

                return Start::FAILURE;
            }

            $label = $database->label;
            $desktop = $source === 'desktop';
            $connections = $this->connectionsOf(
                [$this->toolchain->php(), 'artisan', 'prytax:claude-connection', '--json', '--no-ansi', ...($desktop ? ['--desktop'] : [])],
                $database->env,
            );
        }

        if ($connections === []) {
            error("Claude isn't on in $label.");
            note('An owner turns it on in Settings → Integrations (Налаштування → Інтеграції), then comes back here.'
                . (in_array($source, ['docker', 'local'], true) ? ' An image older than Claude has no such command: ./start or ./prytax docker rebuilds it.' : ''));

            return Start::FAILURE;
        }

        foreach ($connections as $connection) {
            $this->show($connection);
            $this->check($connection);
        }

        return Start::SUCCESS;
    }

    /**
     * Get the data Claude may read, by key, what's started last first: the web app here on its SQLite file, the desktop
     * app's, `.env`'s, Docker's (while it runs), or another SQLite file; each says when its app runs.
     *
     * @return array<string, array{label: string, database: Database|null}>
     */
    protected function sources(): array
    {
        $running = $this->listeners->running();
        $runs = fn (string $app) => isset($running[$app]) ? ' (runs now)' : '';
        $last = app(StarterState::class)->last();
        $sources = [];

        if ($last !== null && $last['choice'] === 'web-here-sqlite' && $last['sqlite'] !== null) {
            $sources['last'] = [
                'label' => 'The web app\'s here — ' . Str::after($last['sqlite'], base_path() . '/') . $runs('web'),
                'database' => Database::sqlite($last['sqlite']),
            ];
        }

        $sources['desktop'] = ['label' => 'The desktop app\'s — database/nativephp.sqlite' . $runs('desktop'), 'database' => Database::desktop($this->toolchain)];
        $sources['web'] = ['label' => ucfirst(Database::web()->label), 'database' => Database::web()];

        if (isset($running['local'])) {
            $sources['local'] = ['label' => "Your local app's — ./start, at {$running['local']['url']} (runs now)", 'database' => null];
        }

        if (isset($running['docker'])) {
            $sources['docker'] = ['label' => "The Docker app's — at {$running['docker']['url']} (runs now)", 'database' => null];
        }

        $sources['sqlite'] = ['label' => 'Another SQLite file', 'database' => null];
        $first = match ($last['choice'] ?? null) {
            'web-here-sqlite' => 'last',
            'web-here-mysql' => 'web',
            'web-docker-sqlite', 'web-docker-mysql' => 'docker',
            'local' => 'local',
            default => 'desktop',
        };

        return isset($sources[$first]) ? [$first => $sources[$first], ...$sources] : $sources;
    }

    /**
     * Get another SQLite file's database, or null, told, when the starter can't reach it.
     *
     * @param string $path
     *
     * @return Database|null
     */
    protected function otherSqlite(string $path): ?Database
    {
        $path = str_starts_with($path, '/') ? $path : base_path($path);

        return $this->reachable($path) ? Database::sqlite($path) : null;
    }

    /**
     * Run `prytax:claude-connection` (here with the database's settings, or in Docker's container), and get the
     * connections it gives, none when Claude isn't on there or it fails.
     *
     * @param list<string> $command
     * @param array<string, string> $env
     *
     * @return list<array<string, mixed>>
     */
    protected function connectionsOf(array $command, array $env): array
    {
        $result = Process::path(base_path())->env($this->toolchain->env($env))->run($command);
        $connections = $result->successful() ? json_decode(trim($result->output()), true) : null;

        return is_array($connections) ? array_values($connections) : [];
    }

    /**
     * Get a command of Docker's compose, with the settings the app runs on: the local app's (`./start`'s, which name
     * its own compose project), or the Docker app's of development.
     *
     * @param bool $local
     * @param list<string> $arguments
     *
     * @return list<string>
     */
    protected function dockerCompose(bool $local, array $arguments): array
    {
        $file = match (true) {
            $local => base_path(Listeners::LOCAL_SETTINGS),
            is_file(base_path('.env.docker')) => base_path('.env.docker'),
            default => base_path('.env'),
        };

        return ['docker', 'compose', '--env-file', $file, ...$arguments];
    }

    /**
     * Print a connection: for Claude Code, and for Claude Desktop.
     *
     * @param array{organization: string, name: string, claude_code: string, claude_desktop: string, notes: list<string>} $connection
     *
     * @return void
     */
    protected function show(array $connection): void
    {
        $output = $this->start->getOutput();
        info("Claude for {$connection['organization']} ({$connection['name']}). Claude Code, in a terminal:");
        $output->writeln($connection['claude_code'], $output::OUTPUT_RAW);
        info('Claude Desktop, in claude_desktop_config.json (Settings → Developer → Edit Config):');
        $output->writeln($connection['claude_desktop'], $output::OUTPUT_RAW);
        note('It carries the key: don\'t share it. A new key on Settings → Integrations stops it.');
    }

    /**
     * Start the server, as Claude would, and tell whether it answers: its tools and the ФОП it sees.
     *
     * @param array{command: string, args: list<string>, env: array<string, string>} $connection
     *
     * @return void
     */
    protected function check(array $connection): void
    {
        $messages = [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'prytax-starter', 'version' => '1']]],
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'],
            ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'list-profiles', 'arguments' => (object) []]],
        ];
        $input = implode(PHP_EOL, array_map(fn (array $message) => json_encode($message), $messages)) . PHP_EOL;

        try {
            $result = spin(fn () => Process::path(base_path())
                ->env($this->toolchain->env($connection['env']))
                ->input($input)
                ->timeout(60)
                ->run([$connection['command'], ...$connection['args']]), 'Asking PryTax\'s server, as Claude would…');
        } catch (Throwable $exception) {
            warning('PryTax\'s server didn\'t answer: ' . $exception->getMessage());

            return;
        }

        // One message a line; not \R, which without /u also splits the byte 0x85 of UTF-8 letters such as «х»
        $answers = collect(explode("\n", trim($result->output())))
            ->map(fn (string $line) => json_decode(trim($line), true))
            ->filter(fn ($answer) => is_array($answer) && isset($answer['id']))
            ->keyBy('id');
        $tools = count($answers[2]['result']['tools'] ?? []);
        $call = $answers[3]['result'] ?? null;

        if ($tools === 0 || $call === null) {
            warning('PryTax\'s server didn\'t answer as it should.');
            note(trim($result->errorOutput()) ?: 'Run the Claude Code command above, then ask Claude about PryTax.');

            return;
        }

        if (!empty($call['isError'])) {
            warning("PryTax's server answers ($tools tools), but refused: " . ($call['content'][0]['text'] ?? ''));

            return;
        }

        $profiles = array_map(fn (array $profile) => $profile['full_name'], $call['structuredContent']['profiles'] ?? []);
        info("PryTax's server answers: $tools tools, reading " . ($profiles === [] ? 'no ФОП' : implode(', ', $profiles)) . '.');
    }
}
