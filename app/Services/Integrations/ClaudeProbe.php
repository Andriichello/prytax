<?php

namespace PryTax\Services\Integrations;

use PryTax\Models\Integration;
use Symfony\Component\Process\ExecutableFinder;
use Throwable;

/**
 * Class ClaudeProbe.
 *
 * Whether Claude on this computer has PryTax's MCP server for an integration (builds.md §7), told by its configs:
 * Claude Code's (`~/.claude.json`, for every project and each one's own) and Claude Desktop's
 * (`claude_desktop_config.json` where each system keeps it). A server is PryTax's when it starts `mcp:start prytax` or
 * carries a key; its key is compared by its hash, and never told. Only where PryTax runs on the person's own computer
 * (the desktop app, a web app here): elsewhere this computer isn't theirs.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Services\Integrations
 */
class ClaudeProbe
{
    /**
     * A config with PryTax's server and the integration's key.
     *
     * @var string
     */
    public const string CURRENT = 'current';

    /**
     * A config with PryTax's server and another key: an old one, or another organization's.
     *
     * @var string
     */
    public const string OTHER_KEY = 'other_key';

    /**
     * A config with PryTax's server without a key.
     *
     * @var string
     */
    public const string NO_KEY = 'no_key';

    /**
     * A config without PryTax's server.
     *
     * @var string
     */
    public const string NONE = 'none';

    /**
     * No config: the client isn't set up on this computer.
     *
     * @var string
     */
    public const string NO_CONFIG = 'no_config';

    /**
     * ClaudeProbe constructor.
     *
     * @param ClaudeConnection $connection
     * @param string|null $home The person's home folder, the environment's by default.
     */
    public function __construct(protected ClaudeConnection $connection, protected ?string $home = null) {}

    /**
     * Determine whether this computer is the person's own: the desktop app, or a web app here, not in a container.
     *
     * @return bool
     */
    public function isAvailable(): bool
    {
        return in_array($this->connection->context(), ['desktop', 'local'], true) && $this->home() !== null;
    }

    /**
     * Probe this computer's Claude Code and Claude Desktop for the integration's server, and whether the `claude`
     * command is found.
     *
     * @param Integration $integration
     *
     * @return array{available: bool, claude_code: array{status: string, servers: list<string>}, claude_desktop: array{status: string, servers: list<string>}, claude_command: bool}
     */
    public function probe(Integration $integration): array
    {
        $none = ['status' => self::NO_CONFIG, 'servers' => []];

        if (!$this->isAvailable()) {
            return ['available' => false, 'claude_code' => $none, 'claude_desktop' => $none, 'claude_command' => false];
        }

        $home = (string) $this->home();
        $code = $this->read("$home/.claude.json");
        $desktop = $this->read($this->desktopConfig($home));
        $codeServers = $code === null ? null : [
            ...(array) ($code['mcpServers'] ?? []),
            ...array_merge(...array_values(array_map(fn ($project) => (array) ($project['mcpServers'] ?? []), (array) ($code['projects'] ?? [])))),
        ];

        return [
            'available' => true,
            'claude_code' => $this->status($codeServers, $integration),
            'claude_desktop' => $this->status($desktop === null ? null : (array) ($desktop['mcpServers'] ?? []), $integration),
            'claude_command' => (new ExecutableFinder)->find('claude', null, ["$home/.local/bin", "$home/.claude/local"]) !== null,
        ];
    }

    /**
     * Get what the servers of a config hold for the integration: the best of them, with the names of PryTax's.
     *
     * @param array<string, mixed>|null $servers Null without a config.
     * @param Integration $integration
     *
     * @return array{status: string, servers: list<string>}
     */
    protected function status(?array $servers, Integration $integration): array
    {
        if ($servers === null) {
            return ['status' => self::NO_CONFIG, 'servers' => []];
        }

        $found = [];

        foreach ($servers as $name => $server) {
            $env = (array) ($server['env'] ?? []);
            $args = array_map('strval', (array) ($server['args'] ?? []));
            $key = $env[ClaudeConnection::KEY_VARIABLE] ?? null;

            if ($key === null && !(in_array('mcp:start', $args, true) && in_array(ClaudeConnection::SERVER, $args, true))) {
                continue;
            }

            $found[(string) $name] = match (true) {
                $key === null => self::NO_KEY,
                $integration->key_hash !== null && hash_equals($integration->key_hash, Integration::hashOf((string) $key)) => self::CURRENT,
                default => self::OTHER_KEY,
            };
        }

        $status = match (true) {
            in_array(self::CURRENT, $found, true) => self::CURRENT,
            in_array(self::OTHER_KEY, $found, true) => self::OTHER_KEY,
            $found !== [] => self::NO_KEY,
            default => self::NONE,
        };

        return ['status' => $status, 'servers' => array_keys($found)];
    }

    /**
     * Get Claude Desktop's config file on this system.
     *
     * @param string $home
     *
     * @return string
     */
    protected function desktopConfig(string $home): string
    {
        return match (PHP_OS_FAMILY) {
            'Darwin' => "$home/Library/Application Support/Claude/claude_desktop_config.json",
            'Windows' => (getenv('APPDATA') ?: "$home/AppData/Roaming") . '/Claude/claude_desktop_config.json',
            default => "$home/.config/Claude/claude_desktop_config.json",
        };
    }

    /**
     * Read a JSON config, or null when there's none or it can't be read.
     *
     * @param string $path
     *
     * @return array<string, mixed>|null
     */
    protected function read(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        try {
            $config = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return is_array($config) ? $config : null;
    }

    /**
     * Get the person's home folder, or null when it isn't known.
     *
     * @return string|null
     */
    protected function home(): ?string
    {
        $home = $this->home ?? (getenv('HOME') ?: getenv('USERPROFILE') ?: null);

        return $home === null ? null : rtrim($home, '/\\');
    }
}
