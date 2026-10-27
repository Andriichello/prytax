<?php

namespace Tests\Feature\Backups;

use Core\Models\File;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PryTax\DataLifecycle\Backup\Internal\ArchiveWriter;
use PryTax\DataLifecycle\Backup\Internal\Csv;
use PryTax\DataLifecycle\Backup\RunSpec;
use PryTax\Models\Invoice;
use PryTax\Models\Profile;
use RuntimeException;
use Tests\Concerns\ActsInProfiles;
use Tests\Concerns\ReadsArchives;
use Tests\TestCase;

/**
 * Class ArchiveWriterTest.
 *
 * A profile's archive (backups.md §2.3, §4.4): only its own rows, encrypted columns decrypted and the tax id's hash
 * left out, the api-core rows its records own and none of other owners, documents read through each row's own disk,
 * the CSV conventions, and a content hash, which only a change of the data changes.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Backups
 */
class ArchiveWriterTest extends TestCase
{
    use ActsInProfiles;
    use ReadsArchives;
    use RefreshDatabase;

    /**
     * A PNG of one pixel.
     *
     * @var string
     */
    private const string PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

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
     * Test that the archive of a profile holds none of the rows of another profile of the organization.
     *
     * @return void
     */
    public function testExportsOnlyTheProfilesOwnRows(): void
    {
        [$profile, $invoice] = $this->profileWithAnInvoice();
        [$other, $otherInvoice] = $this->profileWithAnInvoice($profile);

        $archive = $this->writeArchive($profile);

        foreach (array_column($archive->manifest['files'], 'path') as $path) {
            foreach ($this->archiveRows($archive, $path) as $row) {
                if (array_key_exists('profile_id', $row)) {
                    $this->assertSame((string) $profile->id, $row['profile_id'], "$path has a row of another profile.");
                }
            }
        }

        $this->assertSame([(string) $invoice->id], array_column($this->archiveRows($archive, 'data/invoices.csv'), 'id'));
        $this->assertSame([(string) $profile->id], array_column($this->archiveRows($archive, 'data/profile.csv'), 'id'));

        $files = $this->archiveRows($archive, 'data/core/files.csv');
        $otherFiles = File::query()->whereIn('fileable_id', [$other->id, $otherInvoice->id])->pluck('id')->map(fn ($id) => (string) $id)->all();
        $this->assertCount(2, $files);
        $this->assertSame([], array_intersect(array_column($files, 'id'), $otherFiles));
    }

    /**
     * Test that encrypted columns are exported decrypted, the snapshot as compact JSON, and the tax id's hash isn't.
     *
     * @return void
     */
    public function testDecryptsTheEncryptedColumnsAndOmitsTheHashOfTheTaxId(): void
    {
        [$profile, $invoice] = $this->profileWithAnInvoice(attributes: ['tax_id' => '3214704821']);
        $this->inProfile($profile, fn () => $invoice->receivingAccount->update(['iban' => 'UA213223130000026007233566001']));

        $archive = $this->writeArchive($profile);

        $row = $this->archiveRows($archive, 'data/profile.csv')[0];
        $this->assertSame('3214704821', $row['tax_id']);
        $this->assertArrayNotHasKey('tax_id_hash', $row);
        $this->assertSame('UA213223130000026007233566001', $this->archiveRows($archive, 'data/accounts.csv')[0]['iban']);

        $snapshot = $this->archiveRows($archive, 'data/invoices.csv')[0]['snapshot'];
        $this->assertSame('3214704821', json_decode($snapshot, true)['profile']['tax_id']);
        $this->assertStringNotContainsString(': ', $snapshot);
        $this->assertSame('4821', $archive->manifest['profile']['tax_id_last4']);
    }

    /**
     * Test that settings and files, which the profile's records own, are exported, and those of a user or of the
     * organization aren't.
     *
     * @return void
     */
    public function testExportsTheApiCoreRowsOfTheProfilesRecordsOnly(): void
    {
        [$profile, $invoice] = $this->profileWithAnInvoice();
        $user = $this->userOf($profile->organization);
        $this->inProfile($profile, fn () => $profile->updateSettings(['alerts.ready_days' => 21]));
        DB::table('settings')->insert([
            ['model_type' => 'models.users', 'model_id' => $user->id, 'key' => 'ui.theme', 'value' => '"dark"'],
            ['model_type' => 'models.organizations', 'model_id' => $profile->organization_id, 'key' => 'ui.theme', 'value' => '"light"'],
        ]);

        $archive = $this->writeArchive($profile);

        $this->assertSame(
            [['models.profiles', (string) $profile->id, 'alerts.ready_days']],
            array_map(fn (array $row) => [$row['model_type'], $row['model_id'], $row['key']], $this->archiveRows($archive, 'data/core/settings.csv'))
        );
        $this->assertEqualsCanonicalizing(
            [['models.profiles', (string) $profile->id], ['models.invoices', (string) $invoice->id]],
            array_map(fn (array $row) => [$row['fileable_type'], $row['fileable_id']], $this->archiveRows($archive, 'data/core/files.csv'))
        );
    }

    /**
     * Test that every document is read through its own row's disk, lands at its `archive_path`, and that a document
     * missing from its disk fails the archive rather than leaving it out.
     *
     * @return void
     */
    public function testReadsEveryDocumentThroughItsOwnDisk(): void
    {
        Storage::fake('public');
        [$profile] = $this->profileWithAnInvoice();
        $signature = File::query()->where('fileable_type', 'models.profiles')->where('fileable_id', $profile->id)->firstOrFail();
        Storage::disk('public')->put('moved/signature.png', base64_decode(self::PNG));
        Storage::disk('local')->delete($signature->path);
        $signature->forceFill(['disk' => 'public', 'path' => 'moved/signature.png'])->saveQuietly();

        $archive = $this->writeArchive($profile);

        $rows = collect($this->archiveRows($archive, 'data/core/files.csv'))->keyBy('id');
        $this->assertSame("documents/profiles/$profile->id/$signature->id-signature.png", $rows[$signature->id]['archive_path']);
        $this->assertSame(base64_decode(self::PNG), $this->archiveFile($archive, $rows[$signature->id]['archive_path']));

        foreach ($rows as $row) {
            $this->assertNotNull($this->archiveFile($archive, $row['archive_path']), "{$row['archive_path']} is missing.");
        }

        $writer = app(ArchiveWriter::class);
        $data = $writer->writeData($profile->id, new RunSpec, DB::connection(), [
            'snapshot_at' => '2026-10-27T02:37:11Z',
            'organization' => ['id' => $profile->organization_id, 'name' => 'Test'],
        ]);
        Storage::disk('public')->delete('moved/signature.png');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("The document documents/profiles/$profile->id/$signature->id-signature.png is missing from its disk.");

        $writer->addDocuments($data);
    }

    /**
     * Test that the files follow the CSV conventions: a BOM, a header of the columns, `\r\n` rows, timestamps in UTC.
     *
     * @return void
     */
    public function testWritesTheConventionsOfTheCsv(): void
    {
        [$profile] = $this->profileWithAnInvoice();
        // Kyiv is UTC+2 after the end of summer time (25.10.2026)
        DB::table('profiles')->where('id', $profile->id)->update(['created_at' => '2026-10-27 05:37:11']);

        $csv = (string) $this->archiveFile($this->writeArchive($profile), 'data/profile.csv');
        [$header, $row] = Csv::parse($csv);

        $this->assertStringStartsWith(Csv::BOM . 'id,organization_id,', $csv);
        $this->assertStringEndsWith("\r\n", $csv);
        $this->assertSame('2026-10-27T03:37:11Z', $row[array_search('created_at', $header, true)]);
    }

    /**
     * Test that the content hash stays the same for unchanged data, whenever the archive is written, and changes with
     * the data.
     *
     * @return void
     */
    public function testHashesTheContentOfTheDataOnly(): void
    {
        [$profile, $invoice] = $this->profileWithAnInvoice();

        $first = $this->writeArchive($profile);
        $this->travel(5)->minutes();
        $second = $this->writeArchive($profile);
        $this->inProfile($profile, fn () => $invoice->update(['notes' => 'Changed']));
        $third = $this->writeArchive($profile);

        $this->assertSame($first->contentHash, $second->contentHash);
        $this->assertNotSame($first->contentHash, $third->contentHash);
    }

    /**
     * Test that the referenced users are exported with their id, name and e-mail only, and the referenced tax rules.
     *
     * @return void
     */
    public function testExportsTheReferencedRowsOnly(): void
    {
        [$profile] = $this->profileWithAnInvoice();
        $user = $this->userOf($profile->organization);
        $this->userOf($profile->organization);
        DB::table('clients')->where('profile_id', $profile->id)->update(['created_by_user_id' => $user->id]);

        $archive = $this->writeArchive($profile);

        $this->assertSame(
            [['id' => (string) $user->id, 'name' => $user->name, 'email' => $user->email]],
            $this->archiveRows($archive, 'data/reference/users.csv')
        );
        // Only the rules, which the ЄСВ months of the profile name
        $rules = DB::table('esv_obligations')->where('profile_id', $profile->id)->distinct()->orderBy('tax_rule_id')->pluck('tax_rule_id');
        $this->assertSame($rules->map(fn ($id) => (string) $id)->all(), array_column($this->archiveRows($archive, 'data/reference/tax_rules.csv'), 'id'));
        $this->assertGreaterThan($rules->count(), DB::table('tax_rules')->count());
        $this->assertNull($this->archiveFile($archive, 'data/core/audit_logs.csv'));
    }

    /**
     * Create a profile (of a new organization, or of the given profile's) with a frozen invoice and a signature.
     *
     * @param Profile|null $of
     * @param array $attributes
     *
     * @return array{0: Profile, 1: Invoice}
     */
    private function profileWithAnInvoice(?Profile $of = null, array $attributes = []): array
    {
        $profile = $this->createProfile($of?->organization, $attributes);
        $this->inProfile($profile, fn () => $profile->attachContents(base64_decode(self::PNG), 'signature.png', $profile::SIGNATURE_COLLECTION));
        $invoice = $this->createFrozenInvoice($profile, $this->createAccount($profile));

        return [$profile, $invoice->fresh()];
    }
}
