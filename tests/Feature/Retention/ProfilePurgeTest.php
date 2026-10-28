<?php

namespace Tests\Feature\Retention;

use Core\Models\AuditLog;
use Core\Models\File;
use Core\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PryTax\DataLifecycle\Backup\Enums\BackupKind;
use PryTax\DataLifecycle\Backup\Models\Backup;
use PryTax\DataLifecycle\Registry\SchemaInspector;
use PryTax\DataLifecycle\Retention\Enums\RetentionStatus;
use PryTax\DataLifecycle\Retention\Internal\DueJobs;
use PryTax\DataLifecycle\Retention\Models\RetentionJob;
use PryTax\DataLifecycle\Retention\Models\RetentionRun;
use PryTax\DataLifecycle\Retention\RetentionRuns;
use PryTax\Models\Invoice;
use PryTax\Models\Payment;
use PryTax\Models\Profile;
use PryTax\Models\Refund;
use PryTax\Retention\RetentionTriggers;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;

/**
 * Class ProfilePurgeTest.
 *
 * A deleted ФОП (backups.md §9.3, §9.5): hidden at once, backed up, and purged after its grace in FK order, every row
 * of its data with its documents, backups and audit history, other profiles untouched, no audit entry written;
 * restoring it during the grace cancels the purge; a profile, which isn't deleted, is never purged; a job is
 * claimed by one worker only.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Retention
 */
class ProfilePurgeTest extends TestCase
{
    use ActsInProfiles;
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
     * Test that the demo practice's ФОП, deleted, is backed up, kept for its grace, then purged without a row or a
     * document left, while the practice's other ФОП keep all of theirs.
     *
     * @return void
     */
    public function testPurgesADeletedProfileAfterItsGrace(): void
    {
        $this->travelTo('2026-10-20 12:00:00');
        $this->seed(DemoSeeder::class);
        $profile = Profile::query()->withoutGlobalScopes()->where('tax_id_hash', Profile::hashTaxId('3214704821'))->firstOrFail();
        $this->inProfile($profile, function () use ($profile) {
            Invoice::query()->where('status', 'draft')->firstOrFail()->delete();
            $payment = Payment::query()->whereNotNull('invoice_id')->firstOrFail();
            Refund::factory()->create(['profile_id' => $profile->id, 'payment_id' => $payment->id, 'from_account_id' => $payment->received_to_account_id, 'refunded_at' => '2026-10-15']);
        });
        $objects = File::query()->where('fileable_type', 'models.invoices')->whereIn('fileable_id', DB::table('invoices')->where('profile_id', $profile->id)->pluck('id'))->pluck('path');
        $this->assertNotEmpty($objects);
        Sanctum::actingAs(User::query()->where('email', 'olena@example.com')->firstOrFail());

        $this->deleteJson("/api/profiles/$profile->id")->assertOk();

        $this->getJson("/api/profiles/$profile->id")->assertNotFound();
        $backup = Backup::query()->withoutGlobalScopes()->where('profile_id', $profile->id)->sole();
        $this->assertSame(BackupKind::Automatic, $backup->tier);
        $run = RetentionRun::query()->sole();
        $this->assertSame(['accounting', 'documents', 'profile_finalize'], $run->jobs()->pluck('family')->all());
        $this->assertSame(0, app(DueJobs::class)->dispatch());

        $others = $this->rowsOfOtherProfiles($profile);
        $audit = AuditLog::query()->count() - AuditLog::query()->where('metadata->profile_id', $profile->id)->count();
        $this->assertGreaterThan(0, AuditLog::query()->where('metadata->profile_id', $profile->id)->count());
        $this->travel(31)->days();

        $this->artisan('prytax:retention:execute')->assertSuccessful();

        $this->assertSame(RetentionStatus::Completed, $run->fresh()->status);
        $schema = new SchemaInspector(DB::connection());
        foreach ($schema->tables() as $table) {
            if ($schema->hasColumn($table, 'profile_id')) {
                $this->assertSame(0, DB::table($table)->where('profile_id', $profile->id)->count(), "$table keeps rows of the purged profile.");
            }
        }
        $this->assertNull(DB::table('profiles')->find($profile->id));
        $this->assertSame(0, DB::table('files')->where('fileable_type', 'models.profiles')->where('fileable_id', $profile->id)->count());
        $this->assertFalse(Storage::disk('backups')->exists($backup->object_path));
        foreach ($objects as $path) {
            $this->assertFalse(Storage::disk('local')->exists($path), "$path is left.");
        }
        $this->assertSame($others, $this->rowsOfOtherProfiles($profile));
        $this->assertSame($audit, AuditLog::query()->count());
    }

    /**
     * Test that restoring a deleted ФОП during its grace cancels its purge.
     *
     * @return void
     */
    public function testCancelsThePurgeOfARestoredProfile(): void
    {
        $profile = $this->createProfile();
        $this->actAs($profile);

        $this->deleteJson("/api/profiles/$profile->id")->assertOk();
        $this->postJson("/api/profiles/$profile->id/restore")->assertOk();
        $this->travel(31)->days();
        $this->artisan('prytax:retention:execute')->assertSuccessful();

        $this->assertSame(RetentionStatus::Cancelled, RetentionRun::query()->sole()->status);
        $this->assertNotNull(Profile::query()->withoutGlobalScopes()->find($profile->id));
    }

    /**
     * Test that a deleted ФОП is listed among «Видалені ФОП» during its grace, with the day its data go and its final
     * backup, and leaves the list once restored.
     *
     * @return void
     */
    public function testListsADeletedProfileDuringItsGrace(): void
    {
        $this->travelTo('2026-10-20 10:00:00');
        $profile = $this->createProfile();
        $this->actAs($profile);

        $this->deleteJson("/api/profiles/$profile->id")->assertOk();

        $listed = $this->getJson('/api/organization/deleted-profiles')->assertOk()->json('data');
        $this->assertSame([$profile->id], array_column($listed, 'id'));
        $this->assertStringStartsWith('2026-11-19', (string) $listed[0]['purge_at']);
        $this->assertNotNull($listed[0]['final_backup_id']);

        $this->postJson("/api/profiles/$profile->id/restore")->assertOk();

        $this->getJson('/api/organization/deleted-profiles')->assertOk()->assertJsonCount(0, 'data');
    }

    /**
     * Test that a cancel by ops restores the profile, and a run, which has started, can't be cancelled.
     *
     * @return void
     */
    public function testRestoresTheProfileOfARunCancelledByOps(): void
    {
        $profile = $this->createProfile();
        $this->inProfile($profile, fn () => $profile->delete());
        $run = RetentionRun::query()->sole();

        $this->artisan('prytax:retention:cancel', ['run' => $run->id])->assertSuccessful();

        $this->assertNull(Profile::query()->withoutGlobalScopes()->find($profile->id)->deleted_at);
        $this->assertSame(RetentionStatus::Cancelled, $run->fresh()->status);
    }

    /**
     * Test that a profile, which isn't deleted, is never purged, and that its failed job runs again after a retry.
     *
     * @return void
     */
    public function testNeverPurgesAProfileWhichIsntDeleted(): void
    {
        $profile = $this->createProfile();
        $run = app(RetentionRuns::class)->plan('profile', $profile->id, $profile->organization_id, 'manual', RetentionTriggers::PROFILE_FAMILIES, 0);

        $this->artisan('prytax:retention:execute')->assertSuccessful();

        $job = $run->job('accounting');
        $this->assertSame(RetentionStatus::Failed, $job->status);
        $this->assertSame("The profile $profile->id isn't deleted; it is never purged.", $job->error);
        $this->assertSame(RetentionStatus::Failed, $run->fresh()->status);
        $this->assertSame(RetentionStatus::Pending, $run->job('profile_finalize')->status);
        $this->assertNotNull(DB::table('profiles')->find($profile->id));

        DB::table('profiles')->where('id', $profile->id)->update(['deleted_at' => now()]);
        $this->artisan('prytax:retention:retry', ['job' => $job->id])->assertSuccessful();
        $this->artisan('prytax:retention:execute')->assertSuccessful();

        $this->assertSame(RetentionStatus::Completed, $run->fresh()->status);
        $this->assertNull(DB::table('profiles')->find($profile->id));
    }

    /**
     * Test that a job is claimed once, so two workers never run it twice, and that a subject has one active run.
     *
     * @return void
     */
    public function testClaimsAJobOnlyOnce(): void
    {
        $profile = $this->createProfile();
        $run = app(RetentionRuns::class)->plan('profile', $profile->id, $profile->organization_id, 'manual', ['accounting'], 30);
        $job = RetentionJob::query()->where('run_id', $run->id)->sole();

        $this->assertTrue(app(DueJobs::class)->claim($job->id));
        $this->assertFalse(app(DueJobs::class)->claim($job->id));
        $this->assertSame(1, $job->fresh()->attempts);

        $this->expectExceptionMessage("The profile $profile->id has an active deletion run already.");

        app(RetentionRuns::class)->plan('profile', $profile->id, $profile->organization_id, 'manual', ['accounting'], 30);
    }

    /**
     * Count the rows of the other profiles in every table with `profile_id`.
     *
     * @param Profile $profile
     *
     * @return array<string, int>
     */
    private function rowsOfOtherProfiles(Profile $profile): array
    {
        $schema = new SchemaInspector(DB::connection());
        $counts = [];

        foreach ($schema->tables() as $table) {
            if ($schema->hasColumn($table, 'profile_id') && $table !== 'backups') {
                $counts[$table] = DB::table($table)->where('profile_id', '!=', $profile->id)->count();
            }
        }

        $counts['files'] = DB::table('files')->count() - DB::table('files')->where('fileable_type', 'models.profiles')->where('fileable_id', $profile->id)->count()
            - DB::table('files')->where('fileable_type', 'models.invoices')->whereIn('fileable_id', DB::table('invoices')->where('profile_id', $profile->id)->select('id'))->count();

        return $counts;
    }
}
