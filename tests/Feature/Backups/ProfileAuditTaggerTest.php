<?php

namespace Tests\Feature\Backups;

use Core\Models\AuditLog;
use Core\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PryTax\Backups\ApiCoreTables;
use PryTax\Models\Client;
use PryTax\Models\InvoiceItem;
use PryTax\Models\TaxPayment;
use Tests\Concerns\ActsInProfiles;
use Tests\Concerns\ReadsArchives;
use Tests\TestCase;

/**
 * Class ProfileAuditTaggerTest.
 *
 * Audit entries of profile data carry `metadata.profile_id` (backups.md §4.2 "Audit entries"): of records, of their
 * children and of the api-core rows they own the current profile, of a profile its own id; changes of the user or
 * the organization stay untagged; metadata, which the entry has, is kept; the entry of a hard-deleted record is in
 * its profile's archive.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Backups
 */
class ProfileAuditTaggerTest extends TestCase
{
    use ActsInProfiles;
    use ReadsArchives;
    use RefreshDatabase;

    /**
     * Test that entries of a profile's record, of a child record and of a setting it owns carry the current profile.
     *
     * @return void
     */
    public function testTagsTheEntriesOfAProfilesRecordsWithTheProfile(): void
    {
        Storage::fake('local');
        $profile = $this->createProfile();
        $invoice = $this->createFrozenInvoice($profile, $this->createAccount($profile));

        $this->inProfile($profile, function () use ($profile) {
            $profile->updateSettings(['alerts.ready_days' => 21]);
            AuditLog::record('reviewed', Setting::query()->where('key', 'alerts.ready_days')->firstOrFail());
        });

        foreach ([Client::class, InvoiceItem::class, Setting::class] as $class) {
            $entry = AuditLog::query()->where('subject_type', (new $class)->getMorphClass())->latest('id')->firstOrFail();

            $this->assertSame($profile->id, $entry->metadata['profile_id'] ?? null, $class);
        }

        $this->assertTrue(AuditLog::query()->where('subject_type', 'models.invoices')->where('subject_id', $invoice->id)->exists());
    }

    /**
     * Test that the entries of a profile carry its own id, also where no profile is current (its creation).
     *
     * @return void
     */
    public function testTagsTheEntriesOfAProfileWithItsOwnId(): void
    {
        $profile = $this->createProfile();

        $entry = AuditLog::query()->where('subject_type', 'models.profiles')->where('event', 'created')->firstOrFail();

        $this->assertSame($profile->id, $entry->metadata['profile_id']);
    }

    /**
     * Test that a change of the user or of the organization during a profile's work stays untagged, and that the
     * entry keeps the metadata it was given.
     *
     * @return void
     */
    public function testLeavesTheEntriesOfTheUserAndTheOrganizationUntagged(): void
    {
        $profile = $this->createProfile();
        $user = $this->userOf($profile->organization);

        $this->inProfile($profile, function () use ($profile, $user) {
            AuditLog::record('renamed', $user, ['metadata' => ['ip' => '10.0.0.1']]);
            AuditLog::record('renamed', $profile->organization);
            AuditLog::record('exported', null, ['metadata' => ['ip' => '10.0.0.2']]);
            AuditLog::record('noted', $profile, ['metadata' => ['ip' => '10.0.0.3']]);
        });

        $entries = AuditLog::query()->whereIn('event', ['renamed', 'exported', 'noted'])->orderBy('id')->pluck('metadata')->all();

        $this->assertSame([['ip' => '10.0.0.1'], null, ['ip' => '10.0.0.2'], ['ip' => '10.0.0.3', 'profile_id' => $profile->id]], $entries);
    }

    /**
     * Test that the entry of a record, which is deleted for good, is in its profile's archive, with the audit log
     * included, and in no other profile's.
     *
     * @return void
     */
    public function testExportsTheEntriesOfADeletedRecordWithItsProfile(): void
    {
        $profile = $this->createProfile();
        $other = $this->createProfile($profile->organization);
        $payment = $this->inProfile($profile, fn () => TaxPayment::factory()->create(['profile_id' => $profile->id]));
        $this->inProfile($profile, fn () => $payment->delete());

        $rows = $this->archiveRows($this->writeArchive($profile, [ApiCoreTables::INCLUDE_AUDIT_LOG => true]), 'data/core/audit_logs.csv');
        $deleted = array_values(array_filter($rows, fn (array $row) => $row['subject_type'] === 'models.tax-payments' && $row['event'] === 'deleted'));

        $this->assertCount(1, $deleted);
        $this->assertSame((string) $payment->id, $deleted[0]['subject_id']);
        $this->assertSame([], array_filter(
            $this->archiveRows($this->writeArchive($other, [ApiCoreTables::INCLUDE_AUDIT_LOG => true]), 'data/core/audit_logs.csv'),
            fn (array $row) => $row['subject_type'] === 'models.tax-payments'
        ));
    }
}
