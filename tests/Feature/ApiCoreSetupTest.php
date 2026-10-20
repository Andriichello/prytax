<?php

namespace Tests\Feature;

use Core\Models\AccessToken;
use Core\Models\AuditLog;
use Core\Models\Organization;
use Core\Models\User;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Class ApiCoreSetupTest.
 *
 * The installation of api-core: its roles, auth routes for tokens and the SPA's sessions, the users of an
 * organization, CORS and the pruning of its models.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature
 */
class ApiCoreSetupTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Seed the roles and their permissions.
     *
     * @var bool
     */
    protected bool $seed = true;

    /**
     * Organization, to which the users belong.
     *
     * @var Organization
     */
    private Organization $organization;

    /**
     * Create the organization.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
    }

    /**
     * Create a user of the organization with the role, whose password is `password123`.
     *
     * @param string $role
     * @param string $email
     *
     * @return User
     */
    private function createUser(string $role, string $email = 'ann@example.com'): User
    {
        $user = User::factory()->create([
            'organization_id' => $this->organization->id,
            'email' => $email,
            'password' => 'password123',
        ]);

        return $user->assignRole($role);
    }

    /**
     * Origin of the SPA, which is the first of Sanctum's stateful domains.
     *
     * @return string
     */
    private function spaOrigin(): string
    {
        return 'http://' . config('sanctum.stateful')[0];
    }

    /**
     * Test that the seeder creates PryTax's roles below the super admin, with their permissions.
     *
     * @return void
     */
    public function testSeedsTheConfiguredRoles(): void
    {
        $this->assertSame(
            ['super-admin', 'owner', 'accountant', 'viewer'],
            Role::query()->orderBy('id')->pluck('name')->all(),
        );

        $this->assertTrue(Role::findByName('owner')->hasPermissionTo('attachRole models.users'));
        $this->assertTrue(Role::findByName('viewer')->hasPermissionTo('viewAny models.users'));
        $this->assertFalse(Role::findByName('accountant')->hasPermissionTo('create models.users'));
    }

    /**
     * Test that a login, which doesn't come from the SPA, gets tokens, and the access token authenticates.
     *
     * @return void
     */
    public function testIssuesTokensOutsideOfTheSpa(): void
    {
        $user = $this->createUser('viewer');

        $token = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password123'])
            ->assertOk()
            ->assertJsonPath('data.token_type', 'Bearer')
            ->json('data.access_token');

        $this->withToken($token)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.roles', ['viewer']);
    }

    /**
     * Test that the SPA signs in to a session, without tokens, and the session cookie then authenticates.
     *
     * @return void
     */
    public function testSignsTheSpaInWithSession(): void
    {
        config(['session.driver' => 'database']);

        $user = $this->createUser('viewer');

        $response = $this->withHeader('Origin', $this->spaOrigin())
            ->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password123'])
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonMissingPath('data.access_token');

        $this->assertSame(0, AccessToken::query()->count());

        $this->app['auth']->forgetGuards();
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');

        $cookie = $response->getCookie(config('session.cookie'));

        $this->withCredentials()
            ->withHeader('Origin', $this->spaOrigin())
            ->withCookie($cookie->getName(), $cookie->getValue())
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);
    }

    /**
     * Test that owners invite users to their organization, and viewers don't.
     *
     * @return void
     */
    public function testLetsOwnersInviteUsers(): void
    {
        Notification::fake();

        Sanctum::actingAs($this->createUser('viewer', 'viewer@example.com'));

        $this->postJson('/api/users', ['name' => 'Bo', 'email' => 'bo@example.com', 'roles' => ['viewer']])
            ->assertForbidden();

        Sanctum::actingAs($this->createUser('owner', 'owner@example.com'));

        $this->postJson('/api/users', ['name' => 'Bo', 'email' => 'bo@example.com', 'roles' => ['accountant']])
            ->assertCreated()
            ->assertJsonPath('data.organization_id', $this->organization->id)
            ->assertJsonPath('data.roles', ['accountant']);

        $this->getJson('/api/users')
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    /**
     * Test that the SPA's origin may send requests with credentials.
     *
     * @return void
     */
    public function testAllowsTheSpaOriginWithCredentials(): void
    {
        $this->withHeaders([
            'Origin' => 'http://localhost:5173',
            'Access-Control-Request-Method' => 'POST',
        ])
            ->options('/api/auth/login')
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173')
            ->assertHeader('Access-Control-Allow-Credentials', 'true');
    }

    /**
     * Test that expired tokens and old audit log entries are pruned daily.
     *
     * @return void
     */
    public function testSchedulesPruningOfApiCoreModels(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn (Event $event) => str_contains($event->command, 'model:prune'));

        $this->assertCount(1, $events);
        $this->assertSame('0 0 * * *', $events->first()->expression);
        $this->assertStringContainsString(AccessToken::class, $events->first()->command);
        $this->assertStringContainsString(AuditLog::class, $events->first()->command);
    }
}
