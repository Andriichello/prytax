<?php

namespace PryTax\Http\Resources;

use Core\Http\Resources\BaseResource;
use Core\Models\User;
use Illuminate\Http\Request;
use PryTax\Models\Integration;
use PryTax\Services\Integrations\ClaudeConnection;
use PryTax\Services\Integrations\ClaudeProbe;

/**
 * Class IntegrationResource.
 *
 * An organization's integration (builds.md §7), as Налаштування → Інтеграції shows it: whether it's on, who turned it
 * on or off and when, the user its other side reads as (chosen, or the one who turned it on), the ФОП it sees, the
 * key's last characters (never the key), what its other side last did, where PryTax runs and whether this computer can
 * be probed for Claude.
 *
 * @property Integration $resource
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Resources
 */
class IntegrationResource extends BaseResource
{
    /**
     * Transform the resource into an array.
     *
     * @param Request $request
     *
     * @return array
     */
    public function toArray(Request $request): array
    {
        $integration = $this->resource;
        $key = $integration->key_hash === null ? null : (string) $integration->key;

        return [
            'kind' => $integration->kind,
            'enabled' => $integration->isEnabled(),
            'enabled_at' => $integration->enabled_at?->toIso8601String(),
            'enabled_by' => self::user($integration->enabledBy),
            'disabled_at' => $integration->disabled_at?->toIso8601String(),
            'disabled_by' => self::user($integration->disabledBy),
            'acting_user_id' => $integration->acting_user_id,
            'acting_user' => self::user($integration->actor()),
            'profile_ids' => $integration->profile_ids,
            'key_hint' => $key === null ? null : substr($key, -4),
            'key_created_at' => $integration->key_created_at?->toIso8601String(),
            'last_connected_at' => $integration->last_connected_at?->toIso8601String(),
            'last_client' => $integration->last_client,
            'last_used_at' => $integration->last_used_at?->toIso8601String(),
            'last_tool' => $integration->last_tool,
            'context' => app(ClaudeConnection::class)->context(),
            'probe_available' => app(ClaudeProbe::class)->isAvailable(),
        ];
    }

    /**
     * Get a user as the page shows them, or null.
     *
     * @param User|null $user
     *
     * @return array{id: int, name: string, email: string}|null
     */
    protected static function user(?User $user): ?array
    {
        return $user === null ? null : ['id' => (int) $user->getKey(), 'name' => (string) $user->name, 'email' => (string) $user->email];
    }
}
