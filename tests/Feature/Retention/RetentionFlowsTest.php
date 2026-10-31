<?php

namespace Tests\Feature\Retention;

use Core\Models\AuditLog;
use Core\Models\Organization;
use Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use PryTax\Backups\PryTaxTableRegistry;
use PryTax\DataLifecycle\Registry\SchemaInspector;
use PryTax\DataLifecycle\Retention\Enums\RetentionStatus;
use PryTax\DataLifecycle\Retention\Models\RetentionRun;
use PryTax\Models\Client;
use PryTax\Models\RecoveryCode;
use PryTax\Retention\RetentionFamilies;
use PryTax\Retention\UserRemovalHandler;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;

/**
 * Class RetentionFlowsTest.
 *
 * The other deletions (backups.md §9.2, §9.3): a user removed through api-core loses its credentials and access and
 * is anonymized at once, its records keeping their creator; a deleted organization is purged after its grace with its
 * ФОП, users and audit log; the rolling cleanup deletes orphaned morph rows and empty runs past their cutoffs only;
 * every table has a retention family.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Retention
 */
class RetentionFlowsTest extends TestCase
{
    use ActsInProfiles;
    use RefreshDatabase;

    /**
     * Test that a removed user's tokens and roles go and its name and e-mail are anonymized, keeping its row for the
     * records it created, and that its e-mail is free for a new account.
     *
     * @return void
     */
    public function testRemovesAUsersCredentialsAccessAndPersonalData(): void
    {
        $profile = $this->createProfile();
        $user = $this->userOf($profile->organization, 'accountant');
        $user->createToken('phone');
        RecoveryCode::query()->create(['user_id' => $user->id, 'code' => RecoveryCode::generate()]);
        $client = $this->inProfile($profile, fn () => Client::factory()->create(['profile_id' => $profile->id, 'created_by_user_id' => $user->id]));
        $email = $user->email;
        $this->actAs($profile);

        $this->deleteJson("/api/users/$user->id")->assertOk();
        $this->artisan('prytax:retention:execute')->assertSuccessful();

        $this->assertSame(RetentionStatus::Completed, RetentionRun::query()->where('subject_type', 'user')->sole()->status);
        $row = DB::table('users')->find($user->id);
        $this->assertSame([UserRemovalHandler::ANONYMOUS_NAME, "deleted+$user->id@invalid"], [$row->name, $row->email]);
        $this->assertFalse(Hash::check('password', $row->password));
        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count());
        $this->assertSame(0, DB::table('recovery_codes')->where('user_id', $user->id)->count());
        $this->assertSame(0, DB::table('model_has_roles')->where('model_id', $user->id)->where('model_type', 'models.users')->count());
        $this->assertSame($user->id, $client->fresh()->created_by_user_id);

        User::factory()->create(['organization_id' => $profile->organization_id, 'email' => $email]);
    }

    /**
     * Test that a deleted organization keeps everything for its grace, then loses its ФОП, users and audit log, and
     * its own row, while another organization keeps all of its.
     *
     * @return void
     */
    public function testPurgesADeletedOrganizationAfterItsGrace(): void
    {
        Storage::fake('local');
        $organization = Organization::factory()->create();
        $profiles = [$this->createProfile($organization), $this->createProfile($organization)];
        $this->createFrozenInvoice($profiles[0], $this->createAccount($profiles[0]));
        $users = [$this->userOf($organization), $this->userOf($organization, 'viewer')];
        $other = $this->createProfile();
        $this->userOf($other->organization);
        AuditLog::record('noted', null, ['organization_id' => $organization->id]);

        $organization->delete();

        $this->assertSame(2, DB::table('backups')->where('organization_id', $organization->id)->where('status', 'completed')->count());
        $this->artisan('prytax:retention:execute')->assertSuccessful();
        $this->assertNotNull(DB::table('organizations')->find($organization->id));

        $this->travel(31)->days();
        $this->artisan('prytax:retention:execute')->assertSuccessful();

        $this->assertSame(RetentionStatus::Completed, RetentionRun::query()->where('subject_type', 'organization')->sole()->status);
        $this->assertNull(DB::table('organizations')->find($organization->id));
        $this->assertSame(0, DB::table('profiles')->where('organization_id', $organization->id)->count());
        $this->assertSame(0, DB::table('users')->whereIn('id', array_map(fn (User $user) => $user->id, $users))->count());
        $this->assertSame(0, DB::table('audit_logs')->where('organization_id', $organization->id)->count());
        $this->assertSame(0, DB::table('backups')->where('organization_id', $organization->id)->count());
        $this->assertSame([], Storage::disk('local')->allFiles("files/models.profiles/{$profiles[0]->id}"));
        $this->assertNotNull(DB::table('profiles')->find($other->id));
        $this->assertSame(1, DB::table('users')->where('organization_id', $other->organization_id)->count());
    }

    /**
     * Test that the rolling cleanup deletes the morph rows of owners, which no longer exist, and the runs without
     * backups, past their cutoffs only.
     *
     * @return void
     */
    public function testCleansUpOrphanedRowsAndEmptyRunsPastTheirCutoffs(): void
    {
        $profile = $this->createProfile();
        DB::table('settings')->insert([
            ['model_type' => 'models.clients', 'model_id' => 999999, 'key' => 'old', 'value' => '1', 'created_at' => now()->subDays(8)],
            ['model_type' => 'models.clients', 'model_id' => 999998, 'key' => 'new', 'value' => '1', 'created_at' => now()->subDay()],
            ['model_type' => 'models.profiles', 'model_id' => $profile->id, 'key' => 'alerts.ready_days', 'value' => '9', 'created_at' => now()->subDays(8)],
        ]);
        DB::table('backup_runs')->insert([
            ['id' => strtolower((string) str()->ulid()), 'organization_id' => $profile->organization_id, 'kind' => 'scheduled', 'scope' => '{"type":"full"}', 'options' => '{}', 'status' => 'completed', 'created_at' => now()->subDays(91)],
            ['id' => strtolower((string) str()->ulid()), 'organization_id' => $profile->organization_id, 'kind' => 'scheduled', 'scope' => '{"type":"full"}', 'options' => '{}', 'status' => 'completed', 'created_at' => now()->subDays(10)],
        ]);

        $this->artisan('prytax:retention:rolling')->assertSuccessful();
        $this->artisan('prytax:retention:execute')->assertSuccessful();

        $this->assertNull(DB::table('data_retention_jobs')->value('error'));
        $this->assertSame(['new', 'alerts.ready_days'], DB::table('settings')->orderBy('id')->pluck('key')->all());
        $this->assertSame(1, DB::table('backup_runs')->count());
        $this->assertSame(RetentionStatus::Completed, RetentionRun::query()->where('subject_type', 'global')->sole()->status);
    }

    /**
     * Test that every table of the database belongs to exactly one retention family: the registry's profile data, or
     * the list of the others.
     *
     * @return void
     */
    public function testGivesEveryTableARetentionFamily(): void
    {
        $profileData = [];

        foreach ((new PryTaxTableRegistry)->tables() as $table) {
            if (!$table->isReference()) {
                $profileData[] = $table->table();
            }
        }

        $this->assertSame([], array_intersect($profileData, array_keys(RetentionFamilies::TABLES)));

        foreach ((new SchemaInspector(DB::connection()))->tables() as $table) {
            $this->assertTrue(
                in_array($table, $profileData, true) || isset(RetentionFamilies::TABLES[$table]),
                "$table has no retention family."
            );
        }
    }
}
