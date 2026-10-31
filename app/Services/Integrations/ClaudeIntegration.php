<?php

namespace PryTax\Services\Integrations;

use Core\Models\User;
use Illuminate\Support\Str;
use PryTax\Models\Integration;

/**
 * Class ClaudeIntegration.
 *
 * Changes to an organization's Claude integration (builds.md §7): turning it on (who and when, and a key the first
 * time) and off (who and when; the key stays, but opens nothing while it's off), a new key (the old one stops
 * working), its configuration (the acting user, the ФОП Claude sees), and what its other side does, which the MCP
 * server records: Claude connecting (its client and version) and its last call.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Services\Integrations
 */
final class ClaudeIntegration
{
    /**
     * The prefix of a key, which tells what it is wherever it's pasted.
     *
     * @var string
     */
    public const string KEY_PREFIX = 'prytax_';

    /**
     * Turn the integration on, by the user, with a key, unless it's on already.
     *
     * @param Integration $integration
     * @param User $user
     *
     * @return void
     */
    public function enable(Integration $integration, User $user): void
    {
        if ($integration->isEnabled()) {
            return;
        }

        $integration->forceFill([
            'enabled_at' => now(),
            'enabled_by_user_id' => $user->getKey(),
            'disabled_at' => null,
            'disabled_by_user_id' => null,
        ]);

        if ($integration->key_hash === null) {
            $this->rekey($integration);
        }
    }

    /**
     * Turn the integration off, by the user, unless it's off already.
     *
     * @param Integration $integration
     * @param User $user
     *
     * @return void
     */
    public function disable(Integration $integration, User $user): void
    {
        if (!$integration->isEnabled()) {
            return;
        }

        $integration->forceFill([
            'enabled_at' => null,
            'enabled_by_user_id' => null,
            'disabled_at' => now(),
            'disabled_by_user_id' => $user->getKey(),
        ]);
    }

    /**
     * Give the integration a new key, which replaces the old one, and get it.
     *
     * @param Integration $integration
     *
     * @return string
     */
    public function rekey(Integration $integration): string
    {
        $key = self::KEY_PREFIX . Str::random(40);
        $integration->forceFill([
            'key' => $key,
            'key_hash' => Integration::hashOf($key),
            'key_created_at' => now(),
        ]);

        return $key;
    }

    /**
     * Configure the integration: the user Claude reads as (null: the one who turned it on) and the ФОП it sees (null:
     * every one), as far as the settings name them.
     *
     * @param Integration $integration
     * @param array{acting_user_id?: int|null, profile_ids?: list<int>|null} $settings
     *
     * @return void
     */
    public function configure(Integration $integration, array $settings): void
    {
        if (array_key_exists('acting_user_id', $settings)) {
            $integration->acting_user_id = $settings['acting_user_id'];
        }

        if (array_key_exists('profile_ids', $settings)) {
            $ids = $settings['profile_ids'];
            $integration->profile_ids = $ids === null ? null : array_values(array_unique(array_map('intval', $ids)));
        }
    }

    /**
     * Record Claude connecting: when, and its client (Claude Code, Claude Desktop) and version.
     *
     * @param Integration $integration
     * @param array{name?: mixed, version?: mixed} $client
     *
     * @return void
     */
    public function recordConnection(Integration $integration, array $client): void
    {
        $integration->forceFill([
            'last_connected_at' => now(),
            'last_client' => [
                'name' => Str::limit((string) ($client['name'] ?? ''), 64, ''),
                'version' => Str::limit((string) ($client['version'] ?? ''), 32, ''),
            ],
        ])->save();
    }

    /**
     * Record Claude's call of a tool.
     *
     * @param Integration $integration
     * @param string $tool
     *
     * @return void
     */
    public function recordUse(Integration $integration, string $tool): void
    {
        $integration->forceFill(['last_used_at' => now(), 'last_tool' => Str::limit($tool, 64, '')])->save();
    }
}
