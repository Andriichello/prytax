<?php

namespace Tests\Feature\Backups;

use Core\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PryTax\DataLifecycle\Backup\BackupRuns;
use PryTax\DataLifecycle\Backup\Internal\ArchiveRepacker;
use PryTax\DataLifecycle\Backup\Internal\BundleBuilder;
use PryTax\DataLifecycle\Backup\Models\BackupPolicy;
use PryTax\Models\Profile;
use Tests\Concerns\ActsInProfiles;
use Tests\Concerns\ReadsArchives;
use Tests\TestCase;

/**
 * Class BackupImportTest.
 *
 * Restoring profiles from a backup's archive in the app (Резервні копії or Новий ФОП → Відновити з архіву): owners
 * only, as new profiles of their organization, never in place; a profile's archive, or a bundle of every profile of a
 * backup, all of them or none; a dry run tells what the archive holds first, and an archive, which can't be restored,
 * is a 422 of `archive`.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Backups
 */
class BackupImportTest extends TestCase
{
    use ActsInProfiles;
    use ReadsArchives;
    use RefreshDatabase;

    /**
     * Fake the files' disk.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    /**
     * Test that a dry run tells the profile and writes nothing, and the import makes it a new profile.
     *
     * @return void
     */
    public function testRestoresTheProfileOfAnArchiveIntoAnotherOrganization(): void
    {
        $profile = $this->createProfile(attributes: ['full_name' => 'Ткаченко Ірина Петрівна']);
        $archive = $this->upload($this->writeArchive($profile)->path);
        $organization = Organization::factory()->create();
        Sanctum::actingAs($this->userOf($organization));

        $dryRun = $this->post('/api/organization/backups/import', ['archive' => $archive, 'dry_run' => true], ['Accept' => 'application/json']);

        $dryRun->assertOk()->assertJsonPath('data.dry_run', true)->assertJsonPath('data.tenants.0.tenant_id', null)
            ->assertJsonPath('data.tenants.0.tenant.full_name', 'Ткаченко Ірина Петрівна');
        $this->assertSame(0, Profile::query()->withoutGlobalScopes()->where('organization_id', $organization->id)->count());

        $import = $this->post('/api/organization/backups/import', ['archive' => $archive], ['Accept' => 'application/json']);

        $import->assertOk()->assertJsonPath('data.dry_run', false);
        $copy = Profile::query()->withoutGlobalScopes()->findOrFail($import->json('data.tenants.0.tenant_id'));
        $this->assertSame([$organization->id, 'Ткаченко Ірина Петрівна'], [$copy->organization_id, $copy->full_name]);
    }

    /**
     * Test that every ФОП of a backup's bundle is restored into an organization, which has no ФОП yet (as it starts):
     * a dry run tells them and writes nothing, then all of them are restored.
     *
     * @return void
     */
    public function testRestoresEveryProfileOfABundleIntoAnOrganizationWithoutOne(): void
    {
        $first = $this->createProfile(attributes: ['full_name' => 'Ткаченко Ірина Петрівна']);
        $this->createProfile($first->organization, ['full_name' => 'Мельник Андрій Іванович']);
        $bundle = $this->upload($this->bundleOf($first->organization_id));
        $organization = Organization::factory()->create();
        Sanctum::actingAs($this->userOf($organization));
        $names = ['Ткаченко Ірина Петрівна', 'Мельник Андрій Іванович'];

        $this->post('/api/organization/backups/import', ['archive' => $bundle, 'dry_run' => true], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.tenants.*.tenant.full_name', $names)
            ->assertJsonPath('data.tenants.*.tenant_id', [null, null]);
        $this->assertSame(0, Profile::query()->withoutGlobalScopes()->where('organization_id', $organization->id)->count());

        $this->post('/api/organization/backups/import', ['archive' => $bundle], ['Accept' => 'application/json'])->assertOk();

        $this->assertEqualsCanonicalizing($names, Profile::query()->withoutGlobalScopes()->where('organization_id', $organization->id)->pluck('full_name')->all());
    }

    /**
     * Test that a bundle, one of whose ФОПs the organization has, restores none of them, not even those before it.
     *
     * @return void
     */
    public function testRestoresNoProfileOfABundleWithOneTheOrganizationHas(): void
    {
        $first = $this->createProfile();
        $second = $this->createProfile($first->organization);
        $bundle = $this->upload($this->bundleOf($first->organization_id));
        $organization = Organization::factory()->create();
        $existing = $this->createProfile($organization, ['tax_id' => $second->tax_id]);
        Sanctum::actingAs($this->userOf($organization));

        $response = $this->post('/api/organization/backups/import', ['archive' => $bundle], ['Accept' => 'application/json']);

        $response->assertUnprocessable()->assertJsonValidationErrors(['archive' => "The organization has this ФОП already (profile $existing->id)"]);
        $this->assertSame([$existing->id], Profile::query()->withoutGlobalScopes()->where('organization_id', $organization->id)->pluck('id')->all());
    }

    /**
     * Test that only owners restore, an accountant gets a 403.
     *
     * @return void
     */
    public function testLetsOnlyOwnersRestore(): void
    {
        $profile = $this->createProfile();
        $archive = $this->upload($this->writeArchive($profile)->path);
        Sanctum::actingAs($this->userOf(Organization::factory()->create(), 'accountant'));

        $this->post('/api/organization/backups/import', ['archive' => $archive, 'dry_run' => true], ['Accept' => 'application/json'])
            ->assertForbidden();
    }

    /**
     * Test that a profile, which the organization has, isn't restored again into it.
     *
     * @return void
     */
    public function testReturns422ForAProfileTheOrganizationHas(): void
    {
        $profile = $this->createProfile();
        $archive = $this->upload($this->writeArchive($profile)->path);
        $this->actAs($profile);

        $response = $this->post('/api/organization/backups/import', ['archive' => $archive, 'dry_run' => true], ['Accept' => 'application/json']);

        $response->assertUnprocessable()->assertJsonValidationErrors(['archive' => "The organization has this ФОП already (profile $profile->id)"]);
    }

    /**
     * Test that an archive downloaded with a password needs it, and opens with it.
     *
     * @return void
     */
    public function testOpensAnArchiveDownloadedWithAPasswordOnlyWithIt(): void
    {
        $profile = $this->createProfile();
        $encrypted = app(ArchiveRepacker::class)->encrypt($this->writeArchive($profile)->path, 'correct-horse-battery');
        $archive = $this->upload($encrypted);
        Sanctum::actingAs($this->userOf(Organization::factory()->create()));
        $import = fn (array $data) => $this->post('/api/organization/backups/import', ['archive' => $archive, 'dry_run' => true, ...$data], ['Accept' => 'application/json']);

        $import([])->assertUnprocessable()->assertJsonValidationErrors(['archive' => 'The archive is protected by a password.']);
        $import(['password' => 'wrong-horse-battery'])->assertUnprocessable()->assertJsonValidationErrors(['archive' => 'The password doesn\'t open the archive.']);
        $import(['password' => 'correct-horse-battery'])->assertOk()->assertJsonPath('data.dry_run', true);
    }

    /**
     * Test that a file, which isn't an archive, is a 422.
     *
     * @return void
     */
    public function testReturns422ForAFileWhichIsntAnArchive(): void
    {
        Sanctum::actingAs($this->userOf(Organization::factory()->create()));

        $response = $this->post('/api/organization/backups/import', ['archive' => UploadedFile::fake()->createWithContent('backup.zip', 'not a zip')], ['Accept' => 'application/json']);

        $response->assertUnprocessable()->assertJsonValidationErrors(['archive' => 'The file isn\'t a zip archive.']);
    }

    /**
     * Get the path of the bundle of a scheduled backup of the organization's ФОПs, as Резервні копії downloads it.
     *
     * @param int $organizationId
     *
     * @return string
     */
    private function bundleOf(int $organizationId): string
    {
        $policy = BackupPolicy::of($organizationId);
        $policy->save();
        $run = app(BackupRuns::class)->scheduled($policy);
        app(BundleBuilder::class)->build($run);

        return Storage::disk((string) $run->bundle_disk)->path((string) $run->bundle_path);
    }

    /**
     * Get the archive as an uploaded file, as the browser sends it.
     *
     * @param string $path
     *
     * @return UploadedFile
     */
    protected function upload(string $path): UploadedFile
    {
        $copy = tempnam(sys_get_temp_dir(), 'upload');
        copy($path, $copy);

        return new UploadedFile($copy, 'prytax_backup.zip', 'application/zip', null, true);
    }
}
