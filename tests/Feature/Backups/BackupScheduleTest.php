<?php

namespace Tests\Feature\Backups;

use Carbon\CarbonImmutable;
use Core\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PryTax\Backups\BackupRunNotification;
use PryTax\DataLifecycle\Backup\BackupRuns;
use PryTax\DataLifecycle\Backup\Enums\BackupKind;
use PryTax\DataLifecycle\Backup\Enums\BackupStatus;
use PryTax\DataLifecycle\Backup\Enums\RunStatus;
use PryTax\DataLifecycle\Backup\Internal\Pruner;
use PryTax\DataLifecycle\Backup\Models\Backup;
use PryTax\DataLifecycle\Backup\Models\BackupPolicy;
use PryTax\DataLifecycle\Backup\Models\BackupRun;
use PryTax\Models\Profile;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;

/**
 * Class BackupScheduleTest.
 *
 * Scheduled backups (backups.md §2.2, §4.3, D8): policies run in the window when due and plan their next run, an
 * organization gets the default policy; the pruner chooses grandfather-father-son when it prunes, so a retention
 * change applies to the backups there are, keeps the newest archive of a profile and the manual ones up to the cap,
 * and lets automatic ones expire; the owners get an e-mail by the policy.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Backups
 */
class BackupScheduleTest extends TestCase
{
    use ActsInProfiles;
    use RefreshDatabase;

    /**
     * A ФОП of the organization.
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
     * Test that an organization gets the default policy, whose run starts in the window when due, and plans the next
     * one; an organization without profiles gets nothing.
     *
     * @return void
     */
    public function testStartsTheDuePoliciesAndPlansTheirNextRun(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-27 12:00', 'Europe/Kyiv'));
        Organization::factory()->create();

        $this->artisan('prytax:backup:dispatch')->assertSuccessful();

        $policy = BackupPolicy::of($this->profile->organization_id);
        $this->assertTrue($policy->exists);
        $this->assertSame(1, BackupPolicy::query()->withoutGlobalScopes()->count());
        $next = $policy->next_run_at->setTimezone('Europe/Kyiv');
        $this->assertSame('2026-10-28', $next->toDateString());
        $this->assertTrue($next->hour >= 1 && $next->hour < 5);
        $this->assertSame(0, BackupRun::query()->withoutGlobalScopes()->count());

        $this->travelTo($next->addMinute());
        $this->artisan('prytax:backup:dispatch')->assertSuccessful();

        $run = BackupRun::query()->withoutGlobalScopes()->sole();
        $this->assertSame([BackupKind::Scheduled, RunStatus::Completed], [$run->kind, $run->status]);
        $this->assertTrue($run->options['skip_unchanged']);
        $this->assertSame('2026-10-29', $policy->fresh()->next_run_at->setTimezone('Europe/Kyiv')->toDateString());

        $this->artisan('prytax:backup:dispatch')->assertSuccessful();
        $this->assertSame(1, BackupRun::query()->withoutGlobalScopes()->count());
    }

    /**
     * Test that a disabled policy doesn't run.
     *
     * @return void
     */
    public function testLeavesADisabledPolicyAlone(): void
    {
        $policy = BackupPolicy::of($this->profile->organization_id);
        $policy->forceFill(['enabled' => false, 'next_run_at' => now()->subMinute()])->save();

        $this->artisan('prytax:backup:dispatch')->assertSuccessful();

        $this->assertSame(0, BackupRun::query()->withoutGlobalScopes()->count());
    }

    /**
     * Test that grandfather-father-son is chosen when pruning: the newest of each of the last days, ISO weeks and
     * months, which have a backup, and that a lower retention prunes the backups, which the higher one kept.
     *
     * @return void
     */
    public function testKeepsTheNewestOfTheLastDaysWeeksAndMonths(): void
    {
        $dates = ['2026-10-20', '2026-10-19', '2026-10-18', '2026-10-17', '2026-10-12', '2026-10-05', '2026-09-28', '2026-09-15', '2026-08-31', '2026-08-10', '2026-07-01'];
        $backups = array_combine($dates, array_map(fn (string $date) => $this->backupAt($date), $dates));
        $this->retention(3, 2, 4);

        app(Pruner::class)->prune();

        // Days: 20, 19, 18; weeks: W43 → 20, W42 → 18; months: October → 20, September → 28, August → 31, July → 1
        $this->assertKept($backups, ['2026-10-20', '2026-10-19', '2026-10-18', '2026-09-28', '2026-08-31', '2026-07-01']);

        $this->retention(3, 2, 2);
        app(Pruner::class)->prune();

        $this->assertKept($backups, ['2026-10-20', '2026-10-19', '2026-10-18', '2026-09-28']);
        $this->assertFalse(Storage::disk('backups')->exists($backups['2026-08-31']->object_path));
        $this->assertNotNull($backups['2026-08-31']->fresh()->pruned_at);
    }

    /**
     * Test that the newest archive of a profile stays whatever the retention, manual backups stay up to the cap, and
     * automatic ones go once they expire.
     *
     * @return void
     */
    public function testKeepsTheNewestArchiveAndTheManualOnesUpToTheCap(): void
    {
        $this->retention(0, 0, 0);
        $scheduled = $this->backupAt('2026-10-01');
        $expired = $this->backupAt('2026-09-01', BackupKind::Automatic, expiresAt: '2026-10-01');
        $current = $this->backupAt('2026-10-02', BackupKind::Automatic, expiresAt: now()->addDay()->toDateTimeString());
        $other = $this->createProfile($this->profile->organization);
        $manual = [];

        for ($i = 0; $i < 22; $i++) {
            $manual[] = $this->backupAt(CarbonImmutable::parse('2026-06-01')->addDays($i)->toDateString(), BackupKind::Manual, $other);
        }

        app(Pruner::class)->prune();

        $this->assertSame(BackupStatus::Pruned, $scheduled->fresh()->status);
        $this->assertSame(BackupStatus::Pruned, $expired->fresh()->status);
        $this->assertSame(BackupStatus::Completed, $current->fresh()->status);
        $this->assertSame([BackupStatus::Pruned, BackupStatus::Pruned], [$manual[0]->fresh()->status, $manual[1]->fresh()->status]);
        $this->assertSame(BackupStatus::Completed, $manual[2]->fresh()->status);

        $this->retention(0, 0, 0);
        $only = $this->backupAt('2026-01-01', profile: $this->createProfile($this->profile->organization));
        app(Pruner::class)->prune();

        $this->assertSame(BackupStatus::Completed, $only->fresh()->status);
    }

    /**
     * Test that the owners get an e-mail of a failed or partial run by default, of every run with `always`, and that
     * accountants don't.
     *
     * @return void
     */
    public function testEmailsTheOwnersByThePolicy(): void
    {
        Notification::fake();
        $owner = $this->userOf($this->profile->organization);
        $accountant = $this->userOf($this->profile->organization, 'accountant');
        $runs = app(BackupRuns::class);

        $runs->manual($this->profile->organization_id, null, null);
        Notification::assertNothingSent();

        DB::table('profiles')->where('id', $this->createProfile($this->profile->organization)->id)->update(['tax_id' => 'broken']);
        $runs->manual($this->profile->organization_id, null, null);
        Notification::assertSentTo($owner, BackupRunNotification::class, fn ($notification) => $notification->run->status === RunStatus::Partial);
        Notification::assertNotSentTo($accountant, BackupRunNotification::class);

        $policy = BackupPolicy::of($this->profile->organization_id);
        $policy->forceFill(['notify' => 'always'])->save();
        DB::table('profiles')->where('tax_id', 'broken')->update(['deleted_at' => now()]);
        $runs->manual($this->profile->organization_id, null, null);

        Notification::assertSentToTimes($owner, BackupRunNotification::class, 2);
        $mail = (new BackupRunNotification(BackupRun::query()->withoutGlobalScopes()->latest('created_at')->firstOrFail()))->toMail($owner);
        $this->assertStringNotContainsString('http', implode(' ', $mail->introLines));
    }

    /**
     * Save the retention of the organization's policy.
     *
     * @param int $daily
     * @param int $weekly
     * @param int $monthly
     *
     * @return void
     */
    private function retention(int $daily, int $weekly, int $monthly): void
    {
        $policy = BackupPolicy::of($this->profile->organization_id);
        $policy->forceFill(['retention_daily' => $daily, 'retention_weekly' => $weekly, 'retention_monthly' => $monthly])->save();
    }

    /**
     * Record a completed backup of a profile, made on the day, with its object.
     *
     * @param string $date
     * @param BackupKind $tier
     * @param Profile|null $profile
     * @param string|null $expiresAt
     *
     * @return Backup
     */
    private function backupAt(string $date, BackupKind $tier = BackupKind::Scheduled, ?Profile $profile = null, ?string $expiresAt = null): Backup
    {
        $profile ??= $this->profile;
        $run = new BackupRun;
        $run->forceFill(['organization_id' => $profile->organization_id, 'kind' => $tier, 'scope' => ['type' => 'full'], 'options' => [], 'status' => RunStatus::Completed])->save();
        $path = "org/$profile->organization_id/profile/$profile->id/$run->id.zip";
        Storage::disk('backups')->put($path, 'zip');

        $backup = new Backup;
        $backup->forceFill([
            'run_id' => $run->id,
            'organization_id' => $profile->organization_id,
            'profile_id' => $profile->id,
            'status' => BackupStatus::Completed,
            'tier' => $tier,
            'disk' => 'backups',
            'object_path' => $path,
            'expires_at' => $expiresAt,
            'created_at' => CarbonImmutable::parse("$date 02:30", 'Europe/Kyiv'),
        ])->save();

        return $backup;
    }

    /**
     * Assert that of the backups by their dates exactly the ones of the dates are kept.
     *
     * @param array<string, Backup> $backups
     * @param array<int, string> $dates
     *
     * @return void
     */
    private function assertKept(array $backups, array $dates): void
    {
        $kept = array_keys(array_filter($backups, fn (Backup $backup) => $backup->fresh()->status === BackupStatus::Completed));

        $this->assertSame($dates, $kept);
    }
}
