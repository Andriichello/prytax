<?php

namespace Tests\Feature\Http\Controllers;

use Core\Models\Organization;
use Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Class RegistrationControllerTest.
 *
 * Registering a new organization from the sign-in page (APP-BUILDS-PLAN.md): only when `prytax.registration` allows
 * it; the owner is verified and signed in at once; an e-mail, which an account has, is refused.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Http\Controllers
 */
class RegistrationControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Invalid registrations, with the field each fails on.
     *
     * @return array<string, array{0: array<string, string>, 1: string}>
     */
    public static function invalidRegistrations(): array
    {
        return [
            'no organization' => [['organization' => ' '], 'organization'],
            'an invalid e-mail' => [['email' => 'olena'], 'email'],
            'a short password' => [['password' => 'short', 'password_confirmation' => 'short'], 'password'],
            'a repetition, which differs' => [['password_confirmation' => 'correct-horse-batterz'], 'password'],
        ];
    }

    /**
     * Test that the status offers registration only when it's on, and tells the installation's first owner apart.
     *
     * @return void
     */
    public function testTellsWhetherRegistrationIsOpenAndForTheFirstOwner(): void
    {
        config(['prytax.registration' => false]);
        $this->getJson('/api/registration')->assertOk()->assertExactJson(['data' => ['enabled' => false, 'first_owner' => false], 'message' => 'OK']);

        config(['prytax.registration' => true]);
        $this->getJson('/api/registration')->assertOk()->assertJsonPath('data', ['enabled' => true, 'first_owner' => true]);

        User::factory()->create();
        $this->getJson('/api/registration')->assertOk()->assertJsonPath('data', ['enabled' => true, 'first_owner' => false]);
    }

    /**
     * Test that a registration creates an active organization and its verified owner, who is signed in at once.
     *
     * @return void
     */
    public function testRegistersAnOrganizationAndSignsItsOwnerIn(): void
    {
        config(['prytax.registration' => true, 'sanctum.stateful' => ['localhost']]);

        $response = $this->withHeader('Referer', 'http://localhost/')
            ->postJson('/api/registration', $this->registration(['email' => ' Olena@Example.com ']));

        $response->assertCreated()->assertJsonPath('data.user.email', 'olena@example.com');
        $organization = Organization::query()->sole();
        $this->assertSame(['Практика Коваленко', 'praktika-kovalenko'], [$organization->name, $organization->slug]);
        $this->assertNotNull($organization->activated_at);
        $user = User::query()->sole();
        $this->assertSame([$organization->id, 'Олена Коваленко', 'en'], [$user->organization_id, $user->name, $user->locale]);
        $this->assertNotNull($user->email_verified_at);
        $this->assertNotNull($user->password_updated_at);
        $this->assertTrue(Hash::check('correct-horse-battery', $user->password));
        $this->assertTrue($user->hasRole('owner'));
        $this->assertAuthenticatedAs($user, 'web');
    }

    /**
     * Test that a second organization of the same name gets a slug of its own.
     *
     * @return void
     */
    public function testGivesASecondOrganizationOfTheSameNameItsOwnSlug(): void
    {
        config(['prytax.registration' => true]);
        $this->postJson('/api/registration', $this->registration())->assertCreated();
        auth('web')->logout();

        $this->postJson('/api/registration', $this->registration(['email' => 'maria@example.com']))->assertCreated();

        $this->assertSame(['praktika-kovalenko', 'praktika-kovalenko-2'], Organization::query()->orderBy('id')->pluck('slug')->all());
    }

    /**
     * Test that registering is a 404, when it's off, and creates nothing.
     *
     * @return void
     */
    public function testReturns404WhenRegistrationIsOff(): void
    {
        config(['prytax.registration' => false]);

        $this->postJson('/api/registration', $this->registration())->assertNotFound();

        $this->assertSame(0, Organization::query()->count());
        $this->assertGuest('web');
    }

    /**
     * Test that an e-mail, which an account has, in any case, is refused with 422.
     *
     * @return void
     */
    public function testReturns422ForAnEmailWithAnAccount(): void
    {
        config(['prytax.registration' => true]);
        User::factory()->create(['email' => 'olena@example.com']);
        $organizations = Organization::query()->count();

        $response = $this->postJson('/api/registration', $this->registration(['email' => 'OLENA@example.com']));

        $response->assertUnprocessable()->assertJsonValidationErrors(['email' => 'An account with this e-mail exists already. Sign in instead.']);
        $this->assertSame(1, User::query()->count());
        $this->assertSame($organizations, Organization::query()->count());
    }

    /**
     * Test that an invalid registration is refused with 422 on its field, and creates nothing.
     *
     * @param array<string, string> $changes
     * @param string $field
     *
     * @return void
     */
    #[DataProvider('invalidRegistrations')]
    public function testReturns422ForAnInvalidRegistration(array $changes, string $field): void
    {
        config(['prytax.registration' => true]);

        $response = $this->postJson('/api/registration', $this->registration($changes));

        $response->assertUnprocessable()->assertJsonValidationErrors([$field]);
        $this->assertSame(0, Organization::query()->count());
    }

    /**
     * Get a valid registration with the changes.
     *
     * @param array<string, string> $changes
     *
     * @return array<string, string>
     */
    protected function registration(array $changes = []): array
    {
        return [
            'organization' => 'Практика Коваленко',
            'name' => 'Олена Коваленко',
            'email' => 'olena@example.com',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
            'locale' => 'en',
            ...$changes,
        ];
    }
}
