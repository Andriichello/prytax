<?php

namespace Tests\Feature\Backups;

use Core\Models\AuditLog;
use Core\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PryTax\DataLifecycle\Backup\BackupRuns;
use PryTax\DataLifecycle\Backup\Enums\BackupStatus;
use PryTax\DataLifecycle\Backup\Models\Backup;
use PryTax\DataLifecycle\Backup\Models\BackupDownload;
use PryTax\DataLifecycle\Backup\Models\BackupPolicy;
use PryTax\Models\Profile;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;
use ZipArchive;

/**
 * Class BackupControllerTest.
 *
 * The organization's backups through the API (backups.md §4.7, D7): owners and accountants start manual runs, list
 * and download backups (signed links, logged and audited, optionally of an archive encrypted with a password),
 * owners delete manual ones and configure the policy; viewers have no access, and another organization's backups
 * are 404s.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Backups
 */
class BackupControllerTest extends TestCase
{
    use ActsInProfiles;
    use RefreshDatabase;

    /**
     * The organization's ФОП.
     *
     * @var Profile
     */
    private Profile $profile;

    /**
     * Fake the disks, and create the ФОП.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('backups');
        $this->profile = $this->createProfile();
    }

    /**
     * Roles, which may start runs and download backups.
     *
     * @return array<string, array{0: string}>
     */
    public static function rolesWithAccess(): array
    {
        return ['owner' => ['owner'], 'accountant' => ['accountant']];
    }

    /**
     * Test that owners and accountants start a manual run of every profile, which answers 202 with the run.
     *
     * @param string $role
     *
     * @return void
     */
    #[DataProvider('rolesWithAccess')]
    public function testStartsAManualRun(string $role): void
    {
        $user = $this->actAs($this->profile, $role);

        $response = $this->postJson('/api/organization/backup-runs')
            ->assertStatus(202)
            ->assertJsonPath('data.kind', 'manual')
            ->assertJsonPath('data.triggered_by_user_id', $user->id);

        $this->getJson('/api/organization/backup-runs/' . $response->json('data.id') . '?include=backups')
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.backups.0.profile_id', $this->profile->id)
            ->assertJsonPath('data.backups.0.has_archive', true)
            ->assertJsonMissingPath('data.backups.0.object_path');
    }

    /**
     * Test that viewers can't see, start or download backups (a bulk export of personal data).
     *
     * @return void
     */
    public function testShutsOutViewers(): void
    {
        $backup = $this->backup();
        $this->actAs($this->profile, 'viewer');

        $this->getJson('/api/organization/backups')->assertForbidden();
        $this->getJson('/api/organization/backup-policy')->assertForbidden();
        $this->postJson('/api/organization/backup-runs')->assertForbidden();
        $this->postJson("/api/organization/backups/$backup->id/download")->assertForbidden();
    }

    /**
     * Test that another organization's backups and runs are 404s.
     *
     * @return void
     */
    public function testHidesAnotherOrganizationsBackups(): void
    {
        $backup = $this->backup();
        $this->actAs($this->createProfile());

        $this->getJson('/api/organization/backups')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson("/api/organization/backups/$backup->id")->assertNotFound();
        $this->getJson("/api/organization/backup-runs/$backup->run_id")->assertNotFound();
        $this->postJson("/api/organization/backups/$backup->id/download")->assertNotFound();
    }

    /**
     * Test that a download is a short-lived signed link, logged with the user and audited.
     *
     * @return void
     */
    public function testIssuesASignedLinkOfTheArchiveAndLogsIt(): void
    {
        $backup = $this->backup();
        $user = $this->actAs($this->profile, 'accountant');

        $this->postJson("/api/organization/backups/$backup->id/download")
            ->assertOk()
            ->assertJsonPath('data.url', fn (string $url) => str_contains($url, $backup->object_path))
            ->assertJsonPath('data.expires_at', now()->addMinutes(10)->toIso8601String());

        $download = BackupDownload::query()->withoutGlobalScopes()->sole();
        $this->assertSame([$backup->id, $user->id, 'profile'], [$download->backup_id, $download->user_id, $download->kind]);
        $entry = AuditLog::query()->where('event', 'backup_downloaded')->sole();
        $this->assertSame([$user->id, $this->profile->organization_id, $backup->id], [$entry->causer_id, $entry->organization_id, $entry->metadata['backup_id']]);
    }

    /**
     * Test that a download with a password is of a copy of the archive, whose entries are encrypted with it.
     *
     * @return void
     */
    public function testEncryptsTheArchiveWithAPassword(): void
    {
        $backup = $this->backup();
        $this->actAs($this->profile);

        $url = $this->postJson("/api/organization/backups/$backup->id/download", ['password' => 'correct horse'])
            ->assertOk()
            ->json('data.url');

        $path = substr((string) parse_url($url, PHP_URL_PATH), 1);
        $this->assertStringStartsWith('tmp/downloads/', $path);
        $local = tempnam(sys_get_temp_dir(), 'test');
        file_put_contents($local, Storage::disk('backups')->get($path));
        $zip = new ZipArchive;
        $zip->open($local);

        $this->assertFalse($zip->getFromName('manifest.json'));
        $zip->setPassword('correct horse');
        $this->assertSame($this->profile->id, json_decode($zip->getFromName('manifest.json'), true)['profile']['id']);

        $zip->close();
        unlink($local);
    }

    /**
     * Test that owners delete a manual backup, whose archive goes and record stays as pruned, and that accountants
     * can't, nor anybody a scheduled one.
     *
     * @return void
     */
    public function testDeletesOnlyAManualBackupAsAnOwner(): void
    {
        $manual = $this->backup();
        $scheduled = Backup::query()->withoutGlobalScopes()->findOrFail(app(BackupRuns::class)->scheduled($this->policy())->backups()->withoutGlobalScopes()->value('id'));

        $this->actAs($this->profile, 'accountant');
        $this->deleteJson("/api/organization/backups/$manual->id")->assertForbidden();

        $this->actAs($this->profile);
        $this->deleteJson("/api/organization/backups/$scheduled->id")->assertForbidden();
        $this->deleteJson("/api/organization/backups/$manual->id")->assertOk();

        $this->assertSame(BackupStatus::Pruned, $manual->fresh()->status);
        $this->assertFalse(Storage::disk('backups')->exists($manual->object_path));
    }

    /**
     * Test that an organization makes five manual runs a day at most.
     *
     * @return void
     */
    public function testLimitsTheManualRunsOfADay(): void
    {
        $this->actAs($this->profile);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/organization/backup-runs', ['profiles' => [$this->profile->id]])->assertStatus(202);
        }

        $this->postJson('/api/organization/backup-runs')
            ->assertStatus(429)
            ->assertJsonPath('message', 'The organization has made 5 manual backups today already.');

        $this->travel(1)->day();
        $this->postJson('/api/organization/backup-runs')->assertStatus(202);
    }

    /**
     * Test that only the organization's own profiles can be listed for a run.
     *
     * @return void
     */
    public function testRefusesAProfileOfAnotherOrganization(): void
    {
        $this->actAs($this->profile);

        $this->postJson('/api/organization/backup-runs', ['profiles' => [$this->createProfile()->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('profiles.0');
    }

    /**
     * Test that the policy has the defaults until an owner changes it, which plans its next run in the window, and
     * that accountants only read it.
     *
     * @return void
     */
    public function testConfiguresThePolicyAsAnOwner(): void
    {
        $this->actAs($this->profile, 'accountant');
        $this->getJson('/api/organization/backup-policy')
            ->assertOk()
            ->assertJsonPath('data.frequency', 'daily')
            ->assertJsonPath('data.retention_monthly', 12);
        $this->putJson('/api/organization/backup-policy', ['frequency' => 'weekly', 'weekday' => 7])->assertForbidden();

        $this->actAs($this->profile);
        $this->putJson('/api/organization/backup-policy', [
            'frequency' => 'weekly',
            'weekday' => 7,
            'retention_monthly' => 36,
            'profiles_mode' => 'selected',
            'profile_ids' => [$this->profile->id],
        ])
            ->assertOk()
            ->assertJsonPath('data.frequency', 'weekly')
            ->assertJsonPath('data.profile_ids', [$this->profile->id]);

        $next = BackupPolicy::of($this->profile->organization_id)->next_run_at->setTimezone('Europe/Kyiv');
        $this->assertSame(7, $next->isoWeekday());
        $this->assertTrue($next->hour >= 1 && $next->hour < 5);

        $this->putJson('/api/organization/backup-policy', ['retention_daily' => 32])->assertJsonValidationErrors('retention_daily');
    }

    /**
     * Test that the bundle of a run is built on demand, with a folder per ФОП, each holding its archive unchanged (an
     * unchanged ФОП its archive before), and is downloaded through a logged link.
     *
     * @return void
     */
    public function testBuildsTheOrganizationsBundleOfARun(): void
    {
        $other = $this->createProfile($this->profile->organization, ['full_name_en' => 'Andrii Melnyk']);
        $policy = $this->policy();
        $runs = app(BackupRuns::class);
        $first = $runs->scheduled($policy);
        DB::table('profiles')->where('id', $other->id)->update(['phone' => '+380501112233']);
        $second = $runs->scheduled($policy);
        $this->actAs($this->profile, 'accountant');

        $this->getJson("/api/organization/backup-runs/$second->id/bundle")->assertOk()->assertJsonPath('data.status', null);
        $this->postJson("/api/organization/backup-runs/$second->id/bundle")->assertStatus(202);
        $url = $this->getJson("/api/organization/backup-runs/$second->id/bundle")
            ->assertOk()
            ->assertJsonPath('data.status', 'ready')
            ->json('data.url');

        $local = tempnam(sys_get_temp_dir(), 'test');
        file_put_contents($local, Storage::disk('backups')->get(substr((string) parse_url($url, PHP_URL_PATH), 1)));
        $zip = new ZipArchive;
        $zip->open($local);
        $manifest = json_decode($zip->getFromName('manifest.json'), true);

        $this->assertSame([$this->profile->id, $other->id], array_column($manifest['profiles'], 'profile_id'));
        $skipped = Backup::query()->withoutGlobalScopes()->where('run_id', $first->id)->where('profile_id', $this->profile->id)->sole();
        $this->assertSame($skipped->id, $manifest['profiles'][0]['backup_id']);
        $this->assertSame("profiles/andrii-melnyk_p$other->id", $manifest['profiles'][1]['folder']);
        $this->assertSame($other->id, json_decode($zip->getFromName("profiles/andrii-melnyk_p$other->id/manifest.json"), true)['profile']['id']);
        $this->assertNotFalse($zip->getFromName("profiles/andrii-melnyk_p$other->id/data/profile.csv"));
        $zip->close();
        unlink($local);

        $this->assertSame('bundle', BackupDownload::query()->withoutGlobalScopes()->sole()->kind);
    }

    /**
     * Test that the status of the backups tells the latest run, the size of the newest archives, the manual runs left
     * today and the schedule's time of day, and that a run's backups say how long they stay.
     *
     * @return void
     */
    public function testReportsTheStatusOfTheBackups(): void
    {
        $backup = $this->backup();
        $this->actAs($this->profile, 'accountant');

        $status = $this->getJson('/api/organization/backup-status')->assertOk()->json('data');

        $this->assertSame([4, 1, 'manual'], [$status['manual_runs_left'], $status['profiles'], $status['latest_run']['kind']]);
        $this->assertSame($backup->bytes, $status['bytes']);
        $this->assertMatchesRegularExpression('/^0[1-4]:\d\d$/', $status['policy']['time_of_day']);
        $this->getJson("/api/organization/backup-runs/$backup->run_id?include=backups")
            ->assertOk()
            ->assertJsonPath('data.backups.0.keep_reason', 'newest')
            ->assertJsonPath('data.backups.0.keep_until', null);
    }

    /**
     * Back up the organization's ФОП manually.
     *
     * @return Backup
     */
    private function backup(): Backup
    {
        $run = app(BackupRuns::class)->manual($this->profile->organization_id, null, null);

        return Backup::query()->withoutGlobalScopes()->where('run_id', $run->id)->sole();
    }

    /**
     * Get the saved policy of the organization.
     *
     * @return BackupPolicy
     */
    private function policy(): BackupPolicy
    {
        $policy = BackupPolicy::of($this->profile->organization_id);
        $policy->save();

        return $policy;
    }
}
