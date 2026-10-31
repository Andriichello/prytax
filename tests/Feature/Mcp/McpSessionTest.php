<?php

namespace Tests\Feature\Mcp;

use Core\Models\Organization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PryTax\Mcp\DatabaseKey;
use PryTax\Mcp\McpSession;
use PryTax\Models\Integration;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;

/**
 * Class McpSessionTest.
 *
 * Who Claude reads as (builds.md §7): the integration its key names, on, and its acting user, signed in; without a
 * key, an unknown one, while off, or with an acting user who can't sign in, a refusal saying what to do; Claude's
 * client recorded on connecting; and a SQLite database read with the key beside it.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Mcp
 */
class McpSessionTest extends TestCase
{
    use ActsInProfiles;
    use RefreshDatabase;

    /**
     * Test that the key opens its integration, as its acting user, signed in.
     *
     * @return void
     */
    public function testTheKeyOpensItsIntegrationAsItsActingUser(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->userOf($organization);
        $viewer = $this->userOf($organization, 'viewer');
        $integration = Integration::factory()->enabled($owner, 'prytax_opens')->create(['acting_user_id' => $viewer->id]);
        config(['prytax.mcp_key' => 'prytax_opens']);

        [$opened, $user] = app(McpSession::class)->open();

        $this->assertTrue($opened->is($integration));
        $this->assertTrue($user->is($viewer));
        $this->assertTrue(Auth::user()->is($viewer));
    }

    /**
     * Test that a missing key, an unknown key, an integration turned off, or an acting user who can't sign in is
     * refused with what to do.
     *
     * @param string $case
     * @param string $message
     *
     * @return void
     */
    #[DataProvider('refusals')]
    public function testRefusesSayingWhatToDo(string $case, string $message): void
    {
        $owner = $this->userOf(Organization::factory()->create());
        $integration = Integration::factory()->enabled($owner, 'prytax_current')->create();
        config(['prytax.mcp_key' => match ($case) {
            'no key' => null,
            'unknown key' => 'prytax_replaced',
            default => 'prytax_current',
        }]);
        match ($case) {
            'off' => $integration->forceFill(['enabled_at' => null, 'disabled_at' => now()])->save(),
            'suspended' => $owner->forceFill(['suspended_at' => now()])->save(),
            default => null,
        };

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage($message);

        app(McpSession::class)->open();
    }

    /**
     * Get the refusals, with what they say.
     *
     * @return array<string, array{string, string}>
     */
    public static function refusals(): array
    {
        return [
            'no key' => ['no key', 'PRYTAX_MCP_KEY isn\'t set'],
            'unknown key' => ['unknown key', 'This key opens nothing'],
            'off' => ['off', 'Claude is turned off'],
            'suspended acting user' => ['suspended', 'Claude\'s acting user can\'t sign in'],
        ];
    }

    /**
     * Test that connecting records Claude's client and version on the key's integration, even while it's off.
     *
     * @return void
     */
    public function testRecordsTheClientConnecting(): void
    {
        $this->travelTo('2026-10-31 22:41:00');
        $integration = Integration::factory()->enabled($this->userOf(Organization::factory()->create()), 'prytax_client')->create();
        $integration->forceFill(['enabled_at' => null])->save();
        config(['prytax.mcp_key' => 'prytax_client']);

        app(McpSession::class)->recordConnection(['name' => 'claude-code', 'version' => '2.1.316']);

        $integration->refresh();
        $this->assertSame(['name' => 'claude-code', 'version' => '2.1.316'], $integration->last_client);
        $this->assertSame('2026-10-31 22:41:00', $integration->last_connected_at->toDateTimeString());
    }

    /**
     * Test that a SQLite database, which keeps its own key beside it, is read with that key.
     *
     * @return void
     */
    public function testReadsASqliteDatabaseWithTheKeyBesideIt(): void
    {
        $path = sys_get_temp_dir() . '/prytax-key-' . Str::random(8) . '.sqlite';
        $key = 'base64:' . base64_encode(random_bytes(32));
        touch($path);
        file_put_contents("$path.key", $key);
        $default = config('database.default');
        config(['database.connections.desktop' => ['driver' => 'sqlite', 'database' => $path], 'database.default' => 'desktop']);

        DatabaseKey::apply();

        // Back to the tests' database, which the test's own transaction is on
        config(['database.default' => $default]);
        $encrypted = Crypt::encryptString('РНОКПП');
        array_map('unlink', [$path, "$path.key"]);
        $this->assertSame($key, config('app.key'));
        $this->assertSame('РНОКПП', (new Encrypter(base64_decode(substr($key, 7)), config('app.cipher')))->decryptString($encrypted));
    }
}
