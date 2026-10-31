<?php

namespace PryTax\Console\Starter;

use Illuminate\Support\Facades\Process;
use PryTax\Console\Commands\Start;
use Throwable;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\select;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\warning;

/**
 * Class McpAction.
 *
 * Tools → Claude (builds.md §7): how Claude Code or Claude Desktop connects to the desktop app's data or the web
 * app's, for each Claude integration, which an owner turned on in Налаштування → Інтеграції (`prytax:claude-connection`
 * with that data's settings): the `claude mcp add …` command and Claude Desktop's JSON; then whether the server
 * answers with it (the handshake, the tools, the ФОП it sees), which the page's status then shows as the starter's
 * connection.
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
        $desktop = select('Whose data should Claude read?', [
            'desktop' => 'The desktop app\'s — database/nativephp.sqlite',
            'web' => ucfirst(Database::web()->label),
        ], default: 'desktop') === 'desktop';
        $database = $desktop ? Database::desktop($this->toolchain) : Database::web();

        if ($database->isNew()) {
            error(ucfirst($database->label) . ' has no data yet: start it, and turn Claude on in Settings → Integrations.');

            return Start::FAILURE;
        }

        $result = Process::path(base_path())
            ->env($this->toolchain->env($database->env))
            ->run([$this->toolchain->php(), 'artisan', 'prytax:claude-connection', '--json', '--no-ansi', ...($desktop ? ['--desktop'] : [])]);
        $connections = $result->successful() ? json_decode(trim($result->output()), true) : null;

        if (!is_array($connections) || $connections === []) {
            error('Claude isn\'t on in ' . $database->label . '.');
            note('An owner turns it on in Settings → Integrations (Налаштування → Інтеграції), then comes back here.');

            return Start::FAILURE;
        }

        foreach ($connections as $connection) {
            $this->show($connection);
            $this->check($connection);
        }

        return Start::SUCCESS;
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
