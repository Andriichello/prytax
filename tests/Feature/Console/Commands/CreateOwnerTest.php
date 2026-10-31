<?php

namespace Tests\Feature\Console\Commands;

use Core\Models\AuditLog;
use Core\Models\Organization;
use Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use PryTax\DataLifecycle\Backup\Internal\ArchiveRepacker;
use PryTax\Models\Profile;
use Tests\Concerns\ActsInProfiles;
use Tests\Concerns\ReadsArchives;
use Tests\TestCase;

/**
 * Class CreateOwnerTest.
 *
 * The first owner of a fresh installation (builds.md §1.3): created once, verified and signed in with
 * their own password; refused once any user exists. With `--from`, the organization starts with the ФОПs of backup
 * archives (an archive's password asked for), all of them or none.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Console\Commands
 */
class CreateOwnerTest extends TestCase
{
    use ActsInProfiles;
    use ReadsArchives;
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
     * Test that the organization starts with the ФОПs of the archives, the name of their organization suggested, an
     * archive's password asked for, and each import recorded as the owner's.
     *
     * @return void
     */
    public function testStartsTheOrganizationWithTheProfilesOfArchives(): void
    {
        Storage::fake('local');
        $first = $this->createProfile(attributes: ['full_name' => 'Ткаченко Ірина Петрівна']);
        $second = $this->createProfile($first->organization, ['full_name' => 'Мельник Андрій Іванович']);
        $plain = $this->writeArchive($first)->path;
        $locked = app(ArchiveRepacker::class)->encrypt($this->writeArchive($second)->path, 'correct-horse-battery');

        $this->artisan('prytax:create-owner', ['--name' => 'Олена Коваленко', '--email' => 'olena@example.com', '--from' => [$plain, $locked]])
            ->expectsQuestion('The password of ' . basename($locked), 'correct-horse-battery')
            ->expectsOutputToContain('Ткаченко Ірина Петрівна')
            ->expectsOutputToContain('Мельник Андрій Іванович')
            ->expectsQuestion('Organization', 'Практика Коваленко')
            ->expectsQuestion('Password', 'correct-horse-battery')
            ->expectsQuestion('Repeat the password', 'correct-horse-battery')
            ->expectsOutput('The owner olena@example.com can sign in now.')
            ->assertSuccessful();

        $owner = User::query()->sole();
        $copies = Profile::query()->withoutGlobalScopes()->where('organization_id', $owner->organization_id)->pluck('full_name')->all();
        $this->assertEqualsCanonicalizing(['Ткаченко Ірина Петрівна', 'Мельник Андрій Іванович'], $copies);
        $this->assertSame([$owner->id, $owner->id], AuditLog::query()->where('event', 'backup_imported')->pluck('causer_id')->all());
    }

    /**
     * Test that archives, which hold the same ФОП twice, create nothing: neither the organization nor its owner.
     *
     * @return void
     */
    public function testCreatesNothingFromArchivesWithTheSameProfileTwice(): void
    {
        Storage::fake('local');
        $profile = $this->createProfile(attributes: ['full_name' => 'Ткаченко Ірина Петрівна']);
        $archive = $this->writeArchive($profile)->path;
        $organizations = Organization::query()->count();

        $this->artisan('prytax:create-owner', ['--organization' => 'Практика', '--name' => 'Олена', '--email' => 'olena@example.com', '--from' => [$archive, $archive]])
            ->expectsQuestion('Password', 'correct-horse-battery')
            ->expectsQuestion('Repeat the password', 'correct-horse-battery')
            ->expectsOutput(basename($archive) . ': The archives hold the ФОП Ткаченко Ірина Петрівна twice: leave one of them. Nothing was created.')
            ->assertFailed();

        $this->assertSame(0, User::query()->withTrashed()->count());
        $this->assertSame($organizations, Organization::query()->count());
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
