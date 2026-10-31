<?php

namespace Tests\Feature\Http\Controllers;

use Core\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PryTax\Models\Integration;
use PryTax\Services\Integrations\ClaudeConnection;
use PryTax\Services\Integrations\ClaudeProbe;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;

/**
 * Class IntegrationControllerTest.
 *
 * The organization's Claude integration on Налаштування → Інтеграції (builds.md §7): off until an owner turns it on,
 * which records who and when and makes a key; turned off, recorded too; the acting user and the ФОП configured within
 * the organization; a new key stopping the old; the connection only while on; the probe of this computer's Claude
 * configs by the key's hash; and nobody but owners.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Http\Controllers
 */
class IntegrationControllerTest extends TestCase
{
    use ActsInProfiles;
    use RefreshDatabase;

    /**
     * The endpoint of the organization's Claude integration.
     *
     * @var string
     */
    private const string URL = '/api/organization/integrations/claude';

    /**
     * Test that the integration is off until an owner turns it on, which records who and when and makes a key.
     *
     * @return void
     */
    public function testIsOffUntilAnOwnerTurnsItOn(): void
    {
        $owner = $this->actAs($this->createProfile());
        $this->travelTo('2026-10-31 22:40:00');

        $this->getJson(self::URL)->assertOk()->assertJsonPath('data.enabled', false)->assertJsonPath('data.key_hint', null);
        $response = $this->putJson(self::URL, ['enabled' => true]);

        $response->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.enabled_at', '2026-10-31T22:40:00+02:00')
            ->assertJsonPath('data.enabled_by.id', $owner->id)
            ->assertJsonPath('data.acting_user.id', $owner->id)
            ->assertJsonPath('data.profile_ids', null);
        $integration = Integration::of($owner->organization_id, Integration::CLAUDE);
        $this->assertStringStartsWith('prytax_', (string) $integration->key);
        $this->assertSame(substr((string) $integration->key, -4), $response->json('data.key_hint'));
        $this->assertSame(Integration::hashOf((string) $integration->key), $integration->key_hash);
    }

    /**
     * Test that turning it off records who and when, and that its connection isn't given then.
     *
     * @return void
     */
    public function testTurnsOffRecordingWhoAndWhen(): void
    {
        $owner = $this->actAs($this->createProfile());
        $this->putJson(self::URL, ['enabled' => true])->assertOk();

        $this->putJson(self::URL, ['enabled' => false])
            ->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.enabled_by', null)
            ->assertJsonPath('data.disabled_by.id', $owner->id);
        $this->getJson(self::URL . '/connection')->assertStatus(409);
        $this->postJson(self::URL . '/key')->assertStatus(409);
    }

    /**
     * Test that the acting user and the ФОП are chosen within the organization, and that null brings back their
     * defaults.
     *
     * @return void
     */
    public function testConfiguresTheActingUserAndProfilesOfTheOrganization(): void
    {
        $profile = $this->createProfile();
        $this->actAs($profile);
        $viewer = $this->userOf($profile->organization, 'viewer');
        $elsewhere = $this->createProfile();
        $stranger = $this->userOf($elsewhere->organization, 'owner');
        $this->putJson(self::URL, ['enabled' => true])->assertOk();

        $this->putJson(self::URL, ['acting_user_id' => $viewer->id, 'profile_ids' => [$profile->id]])
            ->assertOk()
            ->assertJsonPath('data.acting_user.id', $viewer->id)
            ->assertJsonPath('data.profile_ids', [$profile->id]);
        $this->putJson(self::URL, ['acting_user_id' => $stranger->id, 'profile_ids' => [$elsewhere->id]])
            ->assertJsonValidationErrors(['acting_user_id', 'profile_ids.0']);
        $this->putJson(self::URL, ['profile_ids' => null])->assertOk()->assertJsonPath('data.profile_ids', null);
    }

    /**
     * Test that a new key replaces the old one, which opens nothing then.
     *
     * @return void
     */
    public function testANewKeyStopsTheOldOne(): void
    {
        $owner = $this->actAs($this->createProfile());
        $this->putJson(self::URL, ['enabled' => true])->assertOk();
        $old = (string) Integration::of($owner->organization_id, Integration::CLAUDE)->key;

        $this->postJson(self::URL . '/key')->assertOk();

        $this->assertNull(Integration::ofKey($old));
        $this->assertNotNull(Integration::ofKey((string) Integration::of($owner->organization_id, Integration::CLAUDE)->key));
    }

    /**
     * Test that the connection, while it's on, starts the server with this PHP and carries the key.
     *
     * @return void
     */
    public function testGivesTheConnectionWithItsKey(): void
    {
        $owner = $this->actAs($this->createProfile());
        $this->putJson(self::URL, ['enabled' => true])->assertOk();
        $key = (string) Integration::of($owner->organization_id, Integration::CLAUDE)->key;

        $response = $this->getJson(self::URL . '/connection');

        $response->assertOk()
            ->assertJsonPath('data.context', 'local')
            ->assertJsonPath('data.command', PHP_BINARY)
            ->assertJsonPath('data.args', [base_path('artisan'), 'mcp:start', ClaudeConnection::SERVER])
            ->assertJsonPath('data.env.' . ClaudeConnection::KEY_VARIABLE, $key);
        $this->assertStringStartsWith('claude mcp add --scope user prytax -e', $response->json('data.claude_code'));
        $this->assertStringContainsString(ClaudeConnection::KEY_VARIABLE . "=$key", $response->json('data.claude_code'));
        $this->assertSame($key, json_decode($response->json('data.claude_desktop'), true)['mcpServers']['prytax']['env'][ClaudeConnection::KEY_VARIABLE]);
    }

    /**
     * Test that the probe finds this computer's Claude Code with the integration's key and Claude Desktop with another,
     * by their hashes, without telling either key.
     *
     * @return void
     */
    public function testProbesThisComputersClaudeConfigsByTheKeysHash(): void
    {
        $owner = $this->actAs($this->createProfile());
        $this->putJson(self::URL, ['enabled' => true])->assertOk();
        $key = (string) Integration::of($owner->organization_id, Integration::CLAUDE)->key;
        $home = sys_get_temp_dir() . '/prytax-probe-' . Str::random(8);
        $server = fn (string $key) => ['command' => 'php', 'args' => ['artisan', 'mcp:start', 'prytax'], 'env' => [ClaudeConnection::KEY_VARIABLE => $key]];
        mkdir("$home/.config/Claude", 0700, true);
        file_put_contents("$home/.claude.json", json_encode(['projects' => ['/srv' => ['mcpServers' => ['prytax' => $server($key)]]]]));
        file_put_contents("$home/.config/Claude/claude_desktop_config.json", json_encode(['mcpServers' => ['prytax-old' => $server('prytax_old')]]));
        $this->app->instance(ClaudeProbe::class, new ClaudeProbe(app(ClaudeConnection::class), $home));

        $response = $this->postJson(self::URL . '/probe');

        array_map('unlink', ["$home/.claude.json", "$home/.config/Claude/claude_desktop_config.json"]);
        array_map('rmdir', ["$home/.config/Claude", "$home/.config", $home]);
        $response->assertOk()
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.claude_code', ['status' => ClaudeProbe::CURRENT, 'servers' => ['prytax']])
            ->assertJsonPath('data.claude_desktop', ['status' => ClaudeProbe::OTHER_KEY, 'servers' => ['prytax-old']]);
        $this->assertStringNotContainsString($key, $response->getContent());
    }

    /**
     * Test that an accountant or a viewer neither sees nor changes the integration.
     *
     * @param string $role
     *
     * @return void
     */
    #[DataProvider('otherRoles')]
    public function testShutsOutEveryoneButOwners(string $role): void
    {
        $this->actAs($this->createProfile(), $role);

        $this->getJson(self::URL)->assertForbidden();
        $this->putJson(self::URL, ['enabled' => true])->assertForbidden();
        $this->getJson(self::URL . '/connection')->assertForbidden();
        $this->assertSame(0, Integration::query()->withoutGlobalScopes()->count());
    }

    /**
     * Get the roles, which aren't owners.
     *
     * @return array<string, array{string}>
     */
    public static function otherRoles(): array
    {
        return ['accountant' => ['accountant'], 'viewer' => ['viewer']];
    }

    /**
     * Test that another organization's integration stays its own.
     *
     * @return void
     */
    public function testKeepsEachOrganizationsIntegrationApart(): void
    {
        $other = Integration::factory()->enabled($this->userOf(Organization::factory()->create()))->create();
        $this->actAs($this->createProfile());

        $this->getJson(self::URL)->assertOk()->assertJsonPath('data.enabled', false);

        $this->assertTrue($other->fresh()->isEnabled());
    }
}
