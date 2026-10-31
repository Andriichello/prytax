<?php

namespace Tests\Feature\Console\Commands;

use Core\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PryTax\Models\Invoice;
use PryTax\Models\Profile;
use PryTax\Services\Invoices\InvoiceWriter;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;

/**
 * Class SummaryTest.
 *
 * An installation's overview (`prytax:summary`, builds.md §6): each ФОП's documents (its invoices' files and its
 * signature) and backup archives (completed ones, exports too, pruned ones not), its latest backup (not an export),
 * the organizations' totals, and the table, which names them.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Console\Commands
 */
class SummaryTest extends TestCase
{
    use ActsInProfiles;
    use RefreshDatabase;

    /**
     * Test that each ФОП's documents and kept archives are summed, its latest backup isn't an export, and its
     * organization's totals add them up.
     *
     * @return void
     */
    public function testSumsEachProfilesDocumentsAndArchivesWithItsLatestBackup(): void
    {
        $organization = Organization::factory()->create();
        $kept = $this->createProfile($organization);
        $empty = $this->createProfile($organization);
        $this->attachFile((new Invoice)->getMorphClass(), $this->createDraftInvoice($kept)->id, 1000);
        $this->attachFile($kept->getMorphClass(), $kept->id, 200);
        $this->createBackup($kept, 'completed', 'scheduled', 500, '2026-10-30 10:00:00');
        $this->createBackup($kept, 'completed', 'export', 700, '2026-10-31 09:00:00');
        $this->createBackup($kept, 'pruned', 'manual', 900, '2026-09-01 10:00:00');

        Artisan::call('prytax:summary', ['--json' => true]);
        $summary = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        [$organizationSummary] = $summary['organizations'];
        $profiles = collect($organizationSummary['profiles'])->keyBy('id');
        $this->assertSame(['count' => 2, 'bytes' => 1200], $profiles[$kept->id]['documents']);
        $this->assertSame(['count' => 2, 'bytes' => 1200], $profiles[$kept->id]['backups']);
        $this->assertSame('2026-10-30T10:00:00+02:00', $profiles[$kept->id]['latest_backup_at']);
        $this->assertSame(['count' => 0, 'bytes' => 0], $profiles[$empty->id]['backups']);
        $this->assertNull($profiles[$empty->id]['latest_backup_at']);
        $this->assertSame(['count' => 2, 'bytes' => 1200], $organizationSummary['documents']);
        $this->assertSame('2026-10-30T10:00:00+02:00', $organizationSummary['latest_backup_at']);
        $this->assertSame(1, $summary['totals']['organizations']);
        $this->assertSame(2, $summary['totals']['profiles']);
        $this->assertNotNull($summary['database']['created_at']);
    }

    /**
     * Test that the table names the organization and its ФОП, with the sizes of their documents.
     *
     * @return void
     */
    public function testPrintsTheOrganizationsWithTheirProfiles(): void
    {
        $organization = Organization::factory()->create(['name' => 'Практика Бондар']);
        $profile = $this->createProfile($organization, ['full_name' => 'Бондар Ірина Василівна']);
        $this->attachFile($profile->getMorphClass(), $profile->id, 2048);

        $code = Artisan::call('prytax:summary');
        $output = Artisan::output();

        $this->assertSame(0, $code);
        $this->assertMatchesRegularExpression('/Практика Бондар \(0 users\)\s*│ 2\.0 KB \(1\)/u', $output);
        $this->assertMatchesRegularExpression('/Бондар І\. В\.\s*│ 2\.0 KB \(1\)/u', $output);
    }

    /**
     * Test that an installation without an organization says so.
     *
     * @return void
     */
    public function testTellsThereIsNoOrganizationYet(): void
    {
        $this->artisan('prytax:summary')
            ->expectsOutputToContain('No organization yet.')
            ->assertSuccessful();
    }

    /**
     * Create a draft invoice of the profile.
     *
     * @param Profile $profile
     *
     * @return Invoice
     */
    private function createDraftInvoice(Profile $profile): Invoice
    {
        $account = $this->createAccount($profile);
        [$client, $contract] = $this->createClientWithContract($profile);

        return $this->inProfile($profile, fn () => app(InvoiceWriter::class)->create($profile, [
            'client_id' => $client->id,
            'contract_id' => $contract->id,
            'receiving_account_id' => $account->id,
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'items' => [['quantity' => '1']],
        ]));
    }

    /**
     * Record a stored file of the model, of the size.
     *
     * @param string $type
     * @param int $id
     * @param int $bytes
     *
     * @return void
     */
    private function attachFile(string $type, int $id, int $bytes): void
    {
        DB::table('files')->insert([
            'fileable_type' => $type, 'fileable_id' => $id, 'name' => 'document.pdf', 'disk' => 'local',
            'path' => "files/$type/$id/" . Str::uuid() . '.pdf', 'mime_type' => 'application/pdf', 'size' => $bytes,
            'sha256' => str_repeat('0', 64), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * Record a backup archive of the profile, in a run of its own.
     *
     * @param Profile $profile
     * @param string $status
     * @param string $tier
     * @param int $bytes
     * @param string $at
     *
     * @return void
     */
    private function createBackup(Profile $profile, string $status, string $tier, int $bytes, string $at): void
    {
        $run = (string) Str::ulid();
        $at = Carbon::parse($at);
        DB::table('backup_runs')->insert([
            'id' => $run, 'organization_id' => $profile->organization_id, 'kind' => $tier, 'scope' => '{}',
            'options' => '{}', 'status' => 'completed', 'created_at' => $at, 'updated_at' => $at,
        ]);
        DB::table('backups')->insert([
            'id' => (string) Str::ulid(), 'run_id' => $run, 'organization_id' => $profile->organization_id,
            'profile_id' => $profile->id, 'status' => $status, 'tier' => $tier, 'bytes' => $bytes,
            'created_at' => $at, 'updated_at' => $at,
        ]);
    }
}
