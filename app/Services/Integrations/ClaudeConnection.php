<?php

namespace PryTax\Services\Integrations;

use Illuminate\Support\Facades\DB;
use PryTax\Helpers\Desktop;
use PryTax\Models\Integration;

/**
 * Class ClaudeConnection.
 *
 * How Claude Code or Claude Desktop starts PryTax's MCP server for an integration (builds.md §7): the command, its
 * arguments and its environment (the integration's key, and the database and storage of this installation, when
 * they aren't `.env`'s), as `claude mcp add …` and as Claude Desktop's `mcpServers` JSON. It depends on where PryTax
 * runs:
 * - `desktop`: the desktop app, with its own PHP, its database, storage and file cache, one person's installation;
 * - `local`: a web app on this computer (`artisan serve`, or the starter), with the PHP, which runs it;
 * - `docker`: the containers, through `docker compose exec`, from PryTax's folder, which the person fills in;
 * - `server`: anywhere else, where Claude would run it on the same computer.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Services\Integrations
 */
final class ClaudeConnection
{
    /**
     * The handle of the local MCP server (`routes/ai.php`).
     *
     * @var string
     */
    public const string SERVER = 'prytax';

    /**
     * The variable, which carries the integration's key to the server.
     *
     * @var string
     */
    public const string KEY_VARIABLE = 'PRYTAX_MCP_KEY';

    /**
     * The placeholder of PryTax's folder on the host, which a container can't know.
     *
     * @var string
     */
    public const string FOLDER = '/path/to/prytax';

    /**
     * Get where PryTax runs: `desktop`, `docker`, `local` or `server`.
     *
     * @return string
     */
    public function context(): string
    {
        return match (true) {
            Desktop::isRunning() => 'desktop',
            is_file('/.dockerenv') => 'docker',
            in_array(PHP_SAPI, ['cli', 'cli-server'], true) => 'local',
            default => 'server',
        };
    }

    /**
     * Get the connection of the integration, which is on, in the context (where PryTax runs now, unless given), with
     * the PHP given, if any.
     *
     * @param Integration $integration
     * @param string|null $context
     * @param string|null $php
     *
     * @return array{context: string, name: string, command: string, args: list<string>, env: array<string, string>, claude_code: string, claude_desktop: string, notes: list<string>}
     */
    public function for(Integration $integration, ?string $context = null, ?string $php = null): array
    {
        $context ??= $this->context();
        $notes = [];

        if ($context === 'docker') {
            $name = 'prytax-docker';
            $command = 'docker';
            // The key passes by its name, from the environment Claude gives the command
            $args = ['compose', '--project-directory', self::FOLDER, '--env-file', self::FOLDER . '/.env.docker', 'exec', '-T',
                '-e', self::KEY_VARIABLE, 'app', 'php', 'artisan', 'mcp:start', self::SERVER];
            $env = [];
            $notes[] = 'folder';
        } else {
            $name = $context === 'desktop' ? 'prytax-desktop' : 'prytax';
            $command = $php ?? $this->php($context);
            $args = [base_path('artisan'), 'mcp:start', self::SERVER];
            $env = $this->environment($context);

            if ($context === 'server') {
                $notes[] = 'server';
            }

            // An AppImage runs from a mount of its own, at another path with each launch
            if (str_contains(base_path(), '/.mount_')) {
                $notes[] = 'appimage';
            }
        }

        $env[self::KEY_VARIABLE] = (string) $integration->key;

        return [
            'context' => $context,
            'name' => $name,
            'command' => $command,
            'args' => $args,
            'env' => $env,
            'claude_code' => $this->claudeCode($name, $command, $args, $env),
            'claude_desktop' => (string) json_encode(
                ['mcpServers' => [$name => ['command' => $command, 'args' => $args, 'env' => $env]]],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ),
            'notes' => $notes,
        ];
    }

    /**
     * Get the PHP, which runs the server in the context: the desktop app's own, the one running this, or `php`.
     *
     * @param string $context
     *
     * @return string
     */
    protected function php(string $context): string
    {
        return match ($context) {
            'desktop' => (string) (config('nativephp-internal.php_binary_path') ?: PHP_BINARY),
            'local' => PHP_BINARY,
            default => 'php',
        };
    }

    /**
     * Get the environment, which points the server at this installation's data, where `.env` doesn't: a SQLite file,
     * another storage folder; and the desktop app's file cache and one person's installation.
     *
     * @param string $context
     *
     * @return array<string, string>
     */
    protected function environment(string $context): array
    {
        $env = [];
        $connection = DB::connection();

        if ($connection->getDriverName() === 'sqlite') {
            $env['DB_CONNECTION'] = 'sqlite';
            $env['DB_DATABASE'] = (string) $connection->getDatabaseName();
        }

        if (realpath(storage_path()) !== realpath(base_path('storage'))) {
            $env['LARAVEL_STORAGE_PATH'] = storage_path();
        }

        if ($context === 'desktop') {
            $env['CACHE_STORE'] = 'file';
            $env['PRYTAX_SINGLE_ORGANIZATION'] = 'true';
        }

        return $env;
    }

    /**
     * Get the command, which adds the server to Claude Code for every project of the person.
     *
     * @param string $name
     * @param string $command
     * @param list<string> $args
     * @param array<string, string> $env
     *
     * @return string
     */
    protected function claudeCode(string $name, string $command, array $args, array $env): string
    {
        $parts = ['claude', 'mcp', 'add', '--scope', 'user', $name];

        foreach ($env as $variable => $value) {
            $parts[] = '-e';
            $parts[] = "$variable=$value";
        }

        $parts = [...$parts, '--', $command, ...$args];

        return implode(' ', array_map(fn (string $part) => preg_match('/^[\w@%+=:,.\/-]+$/', $part) ? $part : escapeshellarg($part), $parts));
    }
}
