<?php

namespace PryTax\Mcp;

use Core\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use PryTax\Models\Integration;
use PryTax\Services\Integrations\ClaudeIntegration;

/**
 * Class McpSession.
 *
 * Who Claude reads as, on each call of a tool of PryTax's MCP server (builds.md §7): the integration, which the key
 * (`PRYTAX_MCP_KEY`) names, only while it's on, and its acting user (the one chosen, else the one who turned it on),
 * signed in, as the API's code expects. Without a key the signed-in user's organization's integration serves, as
 * tests sign in. Otherwise it refuses with what to do, which Claude passes on.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Mcp
 */
class McpSession
{
    /**
     * McpSession constructor.
     *
     * @param ClaudeIntegration $claude
     */
    public function __construct(protected ClaudeIntegration $claude) {}

    /**
     * Open a call: the integration, on, and its acting user, signed in.
     *
     * @return array{0: Integration, 1: User}
     * @throws AuthorizationException
     */
    public function open(): array
    {
        $key = $this->key();
        $integration = $key === null ? $this->ofSignedInUser() : Integration::ofKey($key);

        if ($integration === null) {
            throw new AuthorizationException($key === null
                ? 'PRYTAX_MCP_KEY isn\'t set: copy the connection from PryTax, Settings → Integrations → Claude (Налаштування → Інтеграції).'
                : 'This key opens nothing: a new one replaced it, or it isn\'t this installation\'s. Copy the connection again from PryTax, Settings → Integrations → Claude.');
        }

        if (!$integration->isEnabled()) {
            throw new AuthorizationException('Claude is turned off for this organization: its owner turns it on in PryTax, Settings → Integrations (Налаштування → Інтеграції).');
        }

        $user = $integration->acting_user_id !== null ? $integration->actingUser : $integration->enabledBy;

        if ($user === null || $user->suspended_at !== null) {
            throw new AuthorizationException('Claude\'s acting user can\'t sign in to PryTax any more: its owner chooses another in Settings → Integrations → Claude.');
        }

        Auth::setUser($user);

        return [$integration, $user];
    }

    /**
     * Get the integration, which serves now, on, or null (without telling why, as `open()` does).
     *
     * @return Integration|null
     */
    public function integration(): ?Integration
    {
        $key = $this->key();
        $integration = $key === null ? $this->ofSignedInUser() : Integration::ofKey($key);

        return $integration?->isEnabled() ? $integration : null;
    }

    /**
     * Record Claude connecting, on the integration, which the key names, whether it's on or not.
     *
     * @param array{name?: mixed, version?: mixed} $client
     *
     * @return void
     */
    public function recordConnection(array $client): void
    {
        $key = $this->key();
        $integration = $key === null ? null : Integration::ofKey($key);

        if ($integration !== null) {
            $this->claude->recordConnection($integration, $client);
        }
    }

    /**
     * Get the key, which Claude gave the server, or null.
     *
     * @return string|null
     */
    protected function key(): ?string
    {
        $key = config('prytax.mcp_key');

        return is_string($key) && trim($key) !== '' ? trim($key) : null;
    }

    /**
     * Get the signed-in user's organization's integration, if it has one.
     *
     * @return Integration|null
     */
    protected function ofSignedInUser(): ?Integration
    {
        $user = Auth::user();

        if (!$user instanceof User) {
            return null;
        }

        $integration = Integration::of((int) $user->organization_id, Integration::CLAUDE);

        return $integration->exists ? $integration : null;
    }
}
