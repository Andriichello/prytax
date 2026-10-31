<?php

namespace Tests\Feature\Console\Commands;

use Core\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PryTax\Models\Integration;
use PryTax\Services\Integrations\ClaudeConnection;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;

/**
 * Class ClaudeConnectionTest.
 *
 * `prytax:claude-connection` (builds.md §7): each Claude integration, which is on, with how Claude starts PryTax's
 * server and its key, and the desktop app's settings with `--desktop`; none on, where to turn Claude on.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Console\Commands
 */
class ClaudeConnectionTest extends TestCase
{
    use ActsInProfiles;
    use RefreshDatabase;

    /**
     * Test that each integration, which is on, gives its connection with its key, and the desktop app's settings
     * with `--desktop`.
     *
     * @return void
     */
    public function testGivesEachIntegrationsConnectionWithItsKey(): void
    {
        $organization = Organization::factory()->create(['name' => 'Практика Коваленко']);
        Integration::factory()->enabled($this->userOf($organization), 'prytax_on')->create();
        Integration::factory()->create();

        Artisan::call('prytax:claude-connection', ['--json' => true, '--desktop' => true]);
        $connections = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertCount(1, $connections);
        $this->assertSame('Практика Коваленко', $connections[0]['organization']);
        $this->assertSame('prytax-desktop', $connections[0]['name']);
        $this->assertSame('prytax_on', $connections[0]['env'][ClaudeConnection::KEY_VARIABLE]);
        $this->assertSame('file', $connections[0]['env']['CACHE_STORE']);
    }

    /**
     * Test that Docker's connection runs compose from PryTax's folder on the host, when the starter names it, or from
     * a placeholder to fill in, which a note tells.
     *
     * @return void
     */
    public function testRunsDockersServerFromTheHostsFolder(): void
    {
        $integration = Integration::factory()->enabled($this->userOf(Organization::factory()->create()))->create();
        $connection = app(ClaudeConnection::class);

        $named = $connection->for($integration, 'docker', folder: '/srv/prytax');
        $unknown = $connection->for($integration, 'docker');

        $this->assertSame(['compose', '--project-directory', '/srv/prytax', '--env-file', '/srv/prytax/.env.docker'], array_slice($named['args'], 0, 5));
        $this->assertSame([], $named['notes']);
        $this->assertSame(ClaudeConnection::FOLDER, $unknown['args'][2]);
        $this->assertSame(['folder'], $unknown['notes']);
    }

    /**
     * Test that, without an integration on, it says where to turn Claude on.
     *
     * @return void
     */
    public function testSaysWhereToTurnClaudeOn(): void
    {
        Integration::factory()->create();

        $this->artisan('prytax:claude-connection')
            ->expectsOutputToContain('Claude isn\'t on here')
            ->assertFailed();
    }
}
