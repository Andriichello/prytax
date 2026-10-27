<?php

namespace Tests\Feature\Backups;

use Closure;
use Core\Helpers\CurrentOrganization;
use Core\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PryTax\DataLifecycle\Backup\BackupRuns;
use PryTax\DataLifecycle\Backup\Enums\BackupKind;
use PryTax\DataLifecycle\Backup\Enums\BackupStatus;
use PryTax\DataLifecycle\Backup\Enums\RunStatus;
use PryTax\DataLifecycle\Backup\Internal\BackupRunner;
use PryTax\DataLifecycle\Backup\Jobs\RunOrganizationBackup;
use PryTax\DataLifecycle\Backup\Models\Backup;
use PryTax\DataLifecycle\Backup\Models\BackupPolicy;
use PryTax\DataLifecycle\Backup\Models\BackupRun;
use PryTax\Models\Profile;
use Tests\Concerns\ActsInProfiles;
use Tests\Concerns\ReadsArchives;
use Tests\TestCase;

/**
 * Class BackupRunnerTest.
 *
 * A run of an organization's backup (backups.md §4.3): an archive of every profile, which isn't deleted, uploaded to
 * the backups disk and verified, with the run's manifest; an unchanged profile skipped by its content hash; a retry
 * resuming the run; one profile's failure making the run partial; runs of an organization kept apart, never dropped.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Backups
 */
class BackupRunnerTest extends TestCase
{
    use ActsInProfiles;
    use ReadsArchives;
    use RefreshDatabase;

    /**
     * Fake the disks of the files and the archives.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('backups');
    }

    /**
     * Test that a run backs up every profile of the organization, which isn't deleted, into the backups disk.
     *
     * @return void
     */
    public function testBacksUpEveryProfileOfTheOrganization(): void
    {
        $organization = Organization::factory()->create();
        $first = $this->createProfile($organization, ['full_name_en' => 'Olena Kovalenko']);
        $second = $this->createProfile($organization);
        $deleted = $this->createProfile($organization);
        $this->inProfile($deleted, fn () => $deleted->delete());
        $this->createProfile();

        $run = app(BackupRuns::class)->manual($organization->id, null, null)->fresh();

        $this->assertSame(RunStatus::Completed, $run->status);
        $this->assertNotNull($run->snapshot_at);
        $backups = Backup::query()->withoutGlobalScopes()->where('run_id', $run->id)->orderBy('profile_id')->get();
        $this->assertSame([$first->id, $second->id], $backups->pluck('profile_id')->all());

        foreach ($backups as $backup) {
            $this->assertSame(BackupStatus::Completed, $backup->status);
            $this->assertSame(BackupKind::Manual, $backup->tier);
            $this->assertSame('backups', $backup->disk);
            $this->assertStringStartsWith("org/$organization->id/profile/$backup->profile_id/", $backup->object_path);
            $this->assertSame(hash('sha256', Storage::disk('backups')->get($backup->object_path)), $backup->sha256);
            $this->assertSame(1, $backup->row_counts['data/profile.csv']);
        }

        $this->assertMatchesRegularExpression("/^prytax_olena-kovalenko_p$first->id\\_\\d{4}-\\d{2}-\\d{2}T\\d{4}Z\\.zip$/", $backups[0]->file_name);
        $manifest = json_decode(Storage::disk('backups')->get("org/$organization->id/runs/$run->id/manifest.json"), true);
        $this->assertSame(2, $manifest['totals']['completed']);
    }

    /**
     * Test that a profile, whose data didn't change since its last backup, is skipped, and backed up again once it
     * changes.
     *
     * @return void
     */
    public function testSkipsAProfileWhoseDataDidntChange(): void
    {
        $profile = $this->createProfile();
        $policy = $this->policyOf($profile);

        $first = app(BackupRuns::class)->scheduled($policy);
        $second = app(BackupRuns::class)->scheduled($policy);
        DB::table('profiles')->where('id', $profile->id)->update(['phone' => '+380501112233']);
        $third = app(BackupRuns::class)->scheduled($policy);

        $this->assertSame(
            [BackupStatus::Completed, BackupStatus::SkippedUnchanged, BackupStatus::Completed],
            array_map(fn (BackupRun $run) => $this->backupOf($run, $profile)->status, [$first, $second, $third])
        );
        $this->assertNull($this->backupOf($second, $profile)->object_path);
        $this->assertCount(2, Storage::disk('backups')->allFiles("org/$profile->organization_id/profile"));
    }

    /**
     * Test that a retried run doesn't back up again the profiles it has done.
     *
     * @return void
     */
    public function testResumesARetriedRunWithoutRedoingItsProfiles(): void
    {
        $organization = Organization::factory()->create();
        $done = $this->createProfile($organization);
        $left = $this->createProfile($organization);
        $run = new BackupRun;
        $run->forceFill(['organization_id' => $organization->id, 'kind' => BackupKind::Manual, 'scope' => ['type' => 'full'], 'options' => [], 'status' => RunStatus::Running])->save();
        $earlier = new Backup;
        $earlier->forceFill(['run_id' => $run->id, 'organization_id' => $organization->id, 'profile_id' => $done->id, 'status' => BackupStatus::Completed, 'tier' => BackupKind::Manual])->save();

        $this->inOrganization($organization, fn () => app(BackupRunner::class)->run($run));

        $backups = Backup::query()->withoutGlobalScopes()->where('run_id', $run->id)->get()->keyBy('profile_id');
        $this->assertSame($earlier->id, $backups[$done->id]->id);
        $this->assertNull($backups[$done->id]->object_path);
        $this->assertSame(BackupStatus::Completed, $backups[$left->id]->status);
        $this->assertSame(RunStatus::Completed, $run->fresh()->status);
    }

    /**
     * Test that a profile, which can't be backed up, fails alone, and makes the run partial.
     *
     * @return void
     */
    public function testFailsOneProfileAndBacksUpTheOthers(): void
    {
        $organization = Organization::factory()->create();
        $broken = $this->createProfile($organization);
        $fine = $this->createProfile($organization);
        DB::table('profiles')->where('id', $broken->id)->update(['tax_id' => 'not a ciphertext']);

        $run = app(BackupRuns::class)->manual($organization->id, null, null)->fresh();

        $this->assertSame(RunStatus::Partial, $run->status);
        $this->assertSame(BackupStatus::Failed, $this->backupOf($run, $broken)->status);
        $this->assertSame('profiles.tax_id can\'t be decrypted with the app\'s keys.', $this->backupOf($run, $broken)->error);
        $this->assertSame(BackupStatus::Completed, $this->backupOf($run, $fine)->status);
    }

    /**
     * Test that an automatic backup of a deleted profile is taken, kept 30 days, and that runs of an organization
     * don't overlap, but are released to run after one another, never dropped.
     *
     * @return void
     */
    public function testBacksUpADeletedProfileAutomaticallyAndKeepsRunsApart(): void
    {
        $profile = $this->createProfile();
        $this->inProfile($profile, fn () => $profile->delete());

        $run = app(BackupRuns::class)->automatic($profile->organization_id, $profile->id, 'profile_deleted');

        $backup = $this->backupOf($run, $profile);
        $this->assertSame(BackupStatus::Completed, $backup->status);
        $this->assertSame(BackupKind::Automatic, $backup->tier);
        $this->assertEqualsWithDelta(now()->addDays(30)->timestamp, $backup->expires_at->timestamp, 5);

        $job = $this->inOrganization($profile->organization, fn () => new RunOrganizationBackup($run->id));
        $overlap = collect($job->middleware())->first(fn ($middleware) => $middleware instanceof WithoutOverlapping);
        $this->assertSame("backups:$profile->organization_id", $overlap->key);
        $this->assertSame(60, $overlap->releaseAfter);
        $this->assertSame(0, $job->tries);
        $this->assertSame(3, $job->maxExceptions);
    }

    /**
     * Test that the weekly verification imports a stored archive in a dry run, writing nothing but its pass, and
     * fails on an archive, which differs from its checksum.
     *
     * @return void
     */
    public function testVerifiesThatAStoredArchiveRestores(): void
    {
        $profile = $this->createProfile();
        $run = app(BackupRuns::class)->manual($profile->organization_id, null, null);
        $organizations = Organization::query()->count();

        $this->artisan('prytax:backup:verify')->assertSuccessful();

        $backup = $this->backupOf($run, $profile);
        $this->assertNotNull($backup->verified_at);
        $this->assertSame($organizations, Organization::query()->count());
        $this->assertSame(1, Profile::query()->withoutGlobalScopes()->count());

        Storage::disk('backups')->put($backup->object_path, 'damaged');
        $backup->forceFill(['verified_at' => null])->saveQuietly();

        $this->artisan('prytax:backup:verify')->assertFailed();
        $this->assertNull($backup->fresh()->verified_at);
    }

    /**
     * Get the policy of the profile's organization.
     *
     * @param Profile $profile
     *
     * @return BackupPolicy
     */
    private function policyOf(Profile $profile): BackupPolicy
    {
        $policy = BackupPolicy::of($profile->organization_id);
        $policy->save();

        return $policy;
    }

    /**
     * Get the profile's backup of the run.
     *
     * @param BackupRun $run
     * @param Profile $profile
     *
     * @return Backup
     */
    private function backupOf(BackupRun $run, Profile $profile): Backup
    {
        return Backup::query()->withoutGlobalScopes()->where('run_id', $run->id)->where('profile_id', $profile->id)->firstOrFail();
    }

    /**
     * Run the callback in the organization.
     *
     * @template TResult
     *
     * @param Organization $organization
     * @param Closure(): TResult $callback
     *
     * @return TResult
     */
    private function inOrganization(Organization $organization, Closure $callback): mixed
    {
        return CurrentOrganization::run($organization, $callback);
    }
}
