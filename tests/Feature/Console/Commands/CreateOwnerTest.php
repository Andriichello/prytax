<?php

namespace Tests\Feature\Console\Commands;

use Core\Models\Organization;
use Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Class CreateOwnerTest.
 *
 * The first owner of a fresh installation (APP-BUILDS-PLAN.md, phase 1): created once, verified and signed in with
 * their own password; refused once any user exists.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Console\Commands
 */
class CreateOwnerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that a fresh installation gets an active organization and its verified owner, who can sign in.
     *
     * @return void
     */
    public function testCreatesTheOrganizationAndItsOwner(): void
    {
        $this->artisan('prytax:create-owner', ['--organization' => 'Практика Коваленко', '--name' => 'Олена Коваленко', '--email' => 'Olena@Example.com'])
            ->expectsQuestion('Password', 'correct-horse-battery')
            ->expectsQuestion('Repeat the password', 'correct-horse-battery')
            ->expectsOutput('The owner olena@example.com can sign in now.')
            ->assertSuccessful();

        $organization = Organization::query()->sole();
        $this->assertSame('praktika-kovalenko', $organization->slug);
        $this->assertNotNull($organization->activated_at);

        $user = User::query()->sole();
        $this->assertSame($organization->id, $user->organization_id);
        $this->assertSame('olena@example.com', $user->email);
        $this->assertSame('uk', $user->locale);
        $this->assertNotNull($user->email_verified_at);
        $this->assertNotNull($user->password_updated_at);
        $this->assertTrue(Hash::check('correct-horse-battery', $user->password));
        $this->assertTrue($user->hasRole('owner'));
    }

    /**
     * Test that an installation with a user, even a deleted one, gets no second owner.
     *
     * @return void
     */
    public function testRefusesWhenTheInstallationHasAUser(): void
    {
        User::factory()->create()->delete();

        $this->artisan('prytax:create-owner', ['--organization' => 'Друга', '--name' => 'Хтось', '--email' => 'someone@example.com'])
            ->expectsOutput('The installation already has users; invite more from the app.')
            ->assertFailed();

        $this->assertSame(1, User::query()->withTrashed()->count());
        $this->assertFalse(Organization::query()->where('name', 'Друга')->exists());
    }

    /**
     * Test that a password, which isn't repeated exactly, creates nothing.
     *
     * @return void
     */
    public function testRejectsAPasswordThatDoesNotMatchItsRepetition(): void
    {
        $this->artisan('prytax:create-owner', ['--organization' => 'Практика', '--name' => 'Олена', '--email' => 'olena@example.com'])
            ->expectsQuestion('Password', 'correct-horse-battery')
            ->expectsQuestion('Repeat the password', 'correct-horse-batterz')
            ->assertFailed();

        $this->assertSame(0, User::query()->withTrashed()->count());
        $this->assertSame(0, Organization::query()->count());
    }
}
