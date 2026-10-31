<?php

namespace Tests\Feature\Backups;

use Core\Models\File;
use Core\Models\Organization;
use Core\Models\Sequence;
use Core\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use PDOException;
use PryTax\DataLifecycle\Backup\ImportException;
use PryTax\DataLifecycle\Backup\Internal\ArchiveImporter;
use PryTax\DataLifecycle\Backup\Internal\Csv;
use PryTax\Enums\TaxType;
use PryTax\Models\Account;
use PryTax\Models\Alert;
use PryTax\Models\Client;
use PryTax\Models\ExchangeRate;
use PryTax\Models\Invoice;
use PryTax\Models\Profile;
use PryTax\Services\Accounts\AccountBalanceCalculator;
use PryTax\Services\Invoices\InvoicePdfRenderer;
use PryTax\Services\Taxes\TaxLiabilityCalculator;
use PryTax\Services\Taxes\TaxReconciler;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\ActsInProfiles;
use Tests\Concerns\MakesInstallationDatabases;
use Tests\Concerns\ReadsArchives;
use Tests\TestCase;
use ZipArchive;

/**
 * Class ArchiveImporterTest.
 *
 * Restoring an archive as a new ФОП of another organization (backups.md §5.1, §5.2): the demo practice's ФОП is
 * exported and imported, and every tax figure, balance and invoice document of the copy is the original's, with the
 * ids inside values pointing at the copy's own rows. A dry run and a mismatch write nothing; a ФОП, which the
 * organization has already, is refused before anything is written; an NBU rate, which this database lacks, is
 * fetched again. A ФОП moves between SQLite and MySQL, the desktop app's and a server's, both ways.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Backups
 */
class ArchiveImporterTest extends TestCase
{
    use ActsInProfiles;
    use MakesInstallationDatabases;
    use ReadsArchives;
    use RefreshDatabase;

    /**
     * The MySQL suite's database (phpunit.xml), which moving a ФОП between the drivers migrates afresh on SQLite.
     *
     * @var string
     */
    private const string MYSQL_DATABASE = 'prytax_test';

    /**
     * A PNG of one pixel.
     *
     * @var string
     */
    private const string PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    /**
     * The ФОП, which is exported.
     *
     * @var Profile
     */
    private Profile $profile;

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
     * Test that the copy has the original's tax figures, balances, documents and settings, of its own rows.
     *
     * @return void
     */
    public function testRestoresAProfileWithEveryFigureOfTheOriginal(): void
    {
        $this->profile = $this->demoProfile();
        $before = $this->figures($this->profile);
        $organization = Organization::factory()->create();

        $result = app(ArchiveImporter::class)->import($this->writeArchive($this->profile)->path, $organization->id);

        $copy = Profile::query()->withoutGlobalScopes()->findOrFail($result->tenantId);
        $this->assertSame($organization->id, $copy->organization_id);
        $this->assertSame('3214704821', $copy->tax_id);
        $this->assertSame($before, $this->figures($copy));
        $this->assertSame(0, $result->skippedFiles);

        $this->inProfile($copy, function () use ($copy) {
            // The settings name the copy's own accounts
            $accounts = Account::query()->pluck('id')->all();
            $this->assertNotEmpty($copy->setting('taxes.payment_account_ids'));
            $this->assertSame([], array_diff($copy->setting('taxes.payment_account_ids'), $accounts));
            $this->assertContains($copy->setting('taxes.default_account_id'), $accounts);

            // The snapshot names the copy's own signature, which its PDF draws
            $invoice = Invoice::query()->whereNotNull('snapshot')->orderBy('id')->firstOrFail();
            $signature = File::query()->find($invoice->snapshot['profile']['signature_file_id']);
            $this->assertSame([$copy->getMorphClass(), $copy->id], [$signature->fileable_type, $signature->fileable_id]);
            $this->assertStringContainsString('data:image/png;base64,', app(InvoicePdfRenderer::class)->html($invoice));

            // Every invoice's primary document is a file of the copy, stored again
            foreach (Invoice::query()->withTrashed()->whereNotNull('primary_file_id')->get() as $invoice) {
                $this->assertSame($invoice->id, $invoice->primaryFile->fileable_id);
                $this->assertTrue($invoice->primaryFile->isStored());
            }

            // The counters continue per client, with their keys naming the copy's clients
            $clients = Client::query()->pluck('id')->all();
            foreach (Sequence::query()->where('model_id', $copy->id)->pluck('key') as $key) {
                $this->assertMatchesRegularExpression('/^invoices:client:(' . implode('|', $clients) . ')(:\d{4})?$/', $key);
            }
            $this->assertNotEmpty($clients);

            // The derived notifications are rebuilt, the deleted draft kept deleted
            $this->assertSame(Alert::query()->count() > 0, $this->inProfile($this->profile, fn () => Alert::query()->count() > 0));
            $this->assertSame(1, Invoice::onlyTrashed()->count());
        });
    }

    /**
     * Test that the users, who created records, are matched by e-mail in the target organization, else left empty,
     * and that an import creates no user.
     *
     * @return void
     */
    public function testMatchesTheUsersByTheirEmailInTheTargetOrganization(): void
    {
        $this->profile = $this->smallProfile();
        $creator = $this->userOf($this->profile->organization);
        DB::table('clients')->where('profile_id', $this->profile->id)->update(['created_by_user_id' => $creator->id]);
        $archive = $this->writeArchive($this->profile)->path;
        $matching = Organization::factory()->create();
        $namesake = User::factory()->create(['organization_id' => $matching->id, 'email' => $creator->email]);
        $users = User::query()->count();

        $matched = app(ArchiveImporter::class)->import($archive, $matching->id)->tenantId;
        $unmatched = app(ArchiveImporter::class)->import($archive, Organization::factory()->create()->id)->tenantId;

        $this->assertSame([$namesake->id], DB::table('clients')->where('profile_id', $matched)->distinct()->pluck('created_by_user_id')->all());
        $this->assertSame([null], DB::table('clients')->where('profile_id', $unmatched)->distinct()->pluck('created_by_user_id')->all());
        $this->assertSame($users, User::query()->count());
    }

    /**
     * Test that a dry run verifies everything and writes nothing, documents included.
     *
     * @return void
     */
    public function testWritesNothingInADryRun(): void
    {
        $this->profile = $this->smallProfile();
        $archive = $this->writeArchive($this->profile)->path;
        $profiles = DB::table('profiles')->count();
        $objects = count(Storage::disk('local')->allFiles());

        $result = app(ArchiveImporter::class)->import($archive, Organization::factory()->create()->id, dryRun: true);

        $this->assertTrue($result->dryRun);
        $this->assertNull($result->tenantId);
        $this->assertGreaterThan(0, $result->rows['payments']);
        $this->assertSame($profiles, DB::table('profiles')->count());
        $this->assertSame($objects, count(Storage::disk('local')->allFiles()));
    }

    /**
     * Test that an archive, whose figures differ after the import, is rolled back with its documents.
     *
     * @return void
     */
    public function testRollsBackAnImportWhoseChecksDiffer(): void
    {
        $this->profile = $this->smallProfile();
        $archive = $this->writeArchive($this->profile)->path;
        $this->tamper($archive, 'data/payments.csv', fn (array $row) => [...$row, 'uah_amount' => bcadd($row['uah_amount'], '1.00', 2)]);
        $profiles = DB::table('profiles')->count();
        $objects = count(Storage::disk('local')->allFiles());

        try {
            app(ArchiveImporter::class)->import($archive, Organization::factory()->create()->id);
            $this->fail('An archive with other figures was imported.');
        } catch (ImportException $exception) {
            $this->assertStringStartsWith('The figures of the imported tenant differ', $exception->getMessage());
        }

        $this->assertSame($profiles, DB::table('profiles')->count());
        $this->assertSame($objects, count(Storage::disk('local')->allFiles()));
    }

    /**
     * Test that a ФОП, which the organization has, deleted ones too, is refused before anything is written, and so
     * is a damaged archive.
     *
     * @return void
     */
    public function testRefusesAProfileTheOrganizationHasAndADamagedArchive(): void
    {
        $this->profile = $this->smallProfile();
        $archive = $this->writeArchive($this->profile)->path;
        $this->inProfile($this->profile, fn () => $this->profile->delete());

        try {
            app(ArchiveImporter::class)->import($archive, $this->profile->organization_id);
            $this->fail('The ФОП was imported next to itself.');
        } catch (ImportException $exception) {
            $this->assertStringContainsString("already, deleted (profile {$this->profile->id})", $exception->getMessage());
        }

        $zip = new ZipArchive;
        $zip->open($archive);
        $zip->addFromString('data/clients.csv', Csv::BOM . "id\r\n");
        $zip->close();

        $this->expectExceptionMessage('data/clients.csv is missing or damaged.');

        app(ArchiveImporter::class)->import($archive, Organization::factory()->create()->id);
    }

    /**
     * Test that a ФОП moves between SQLite and MySQL both ways, as from the desktop app to a server and back, each
     * installation with its own key: every figure and document of each copy is the original's.
     *
     * @return void
     */
    public function testMovesAProfileBetweenSqliteAndMysql(): void
    {
        $home = (string) config('database.default');
        $this->prepareOtherDriver($home);
        $this->profile = $this->demoProfile();
        $before = $this->figures($this->profile);
        $archive = $this->writeArchive($this->profile)->path;
        $homeKey = (string) config('app.key');
        $rates = DB::table('exchange_rates')->get()->map(fn (object $rate) => (array) $rate)->all();

        $this->useOtherDriver($home);
        $this->useKey($this->makeKey());
        DB::table('exchange_rates')->insert($rates);
        $copy = Profile::query()->withoutGlobalScopes()->findOrFail(app(ArchiveImporter::class)->import($archive, Organization::factory()->create()->id)->tenantId);

        $this->assertNotSame(DB::connection($home)->getDriverName(), DB::connection()->getDriverName());
        $this->assertSame('3214704821', $copy->tax_id);
        $this->assertSame(Profile::hashTaxId('3214704821'), $copy->tax_id_hash);
        $this->assertNotSame($this->profile->tax_id_hash, $copy->tax_id_hash);
        $this->assertSame($before, $this->figures($copy));
        $back = $this->writeArchive($copy)->path;

        $this->useHome($home);
        $this->useKey($homeKey);
        $returned = app(ArchiveImporter::class)->import($back, Organization::factory()->create()->id)->tenantId;

        $this->assertSame($before, $this->figures(Profile::query()->withoutGlobalScopes()->findOrFail($returned)));
    }

    /**
     * Seed the demo practice, and give its ФОП Коваленко О. П. a signature, which one invoice's snapshot names, and a
     * deleted draft.
     *
     * @return Profile
     */
    private function demoProfile(): Profile
    {
        $this->travelTo('2026-10-20 12:00:00');
        $this->seed(DemoSeeder::class);

        $profile = Profile::query()->withoutGlobalScopes()->where('tax_id_hash', Profile::hashTaxId('3214704821'))->firstOrFail();

        $this->inProfile($profile, function () use ($profile) {
            $signature = $profile->attachContents(base64_decode(self::PNG), 'signature.png', Profile::SIGNATURE_COLLECTION);
            $invoice = Invoice::query()->whereNotNull('snapshot')->orderBy('id')->firstOrFail();
            $invoice->forceFill(['snapshot' => array_replace_recursive($invoice->snapshot, ['profile' => ['signature_file_id' => $signature->id]])])->saveQuietly();

            Invoice::query()->where('status', 'draft')->firstOrFail()->delete();
        });

        return $profile;
    }

    /**
     * Test that an NBU rate, which the archive references and this database lacks (a new installation's), is fetched
     * again, and that the import is refused while it can't be.
     *
     * @return void
     */
    public function testFetchesTheNbuRatesWhichThisDatabaseLacks(): void
    {
        $archive = $this->writeArchive($this->smallProfile())->path;
        DB::table('exchange_rates')->delete();
        $organization = Organization::factory()->create();
        $online = false;
        Sleep::fake();
        Http::preventStrayRequests();
        Http::fake(['bank.gov.ua/*date=20261005*' => function () use (&$online) {
            return $online ? Http::response('[{"rate":41.4500,"cc":"USD"}]') : Http::response('', 503);
        }]);

        try {
            app(ArchiveImporter::class)->import($archive, $organization->id);
            $this->fail('The import went on without the rate.');
        } catch (ImportException $exception) {
            $this->assertSame('The NBU rate of USD on 05.10.2026, which the archive references, couldn\'t be fetched. Connect to the internet, and try again.', $exception->getMessage());
        }

        $online = true;
        $result = app(ArchiveImporter::class)->import($archive, $organization->id);

        $this->assertNotNull($result->tenantId);
        $this->assertSame('41.4500', ExchangeRate::query()->where('currency', 'USD')->whereDate('rate_date', '2026-10-05')->sole()->rate);
    }

    /**
     * Create a ФОП with a frozen invoice and its receipt.
     *
     * @return Profile
     */
    private function smallProfile(): Profile
    {
        $this->cacheRates(['USD' => ['2026-10-05' => '41.4500']]);
        $profile = $this->createProfile();
        $account = $this->createAccount($profile);
        $this->recordReceipt($profile, $this->createFrozenInvoice($profile, $account), $account);

        return $profile;
    }

    /**
     * Get the profile's tax figures, account balances and invoice documents.
     *
     * @param Profile $profile
     *
     * @return array<string, mixed>
     */
    private function figures(Profile $profile): array
    {
        return $this->inProfile($profile, function () use ($profile) {
            $liabilities = [];
            $balances = [];

            foreach ([2025, 2026] as $year) {
                foreach ([1, 2, 3, 4] as $quarter) {
                    $liabilities["$year-$quarter"] = app(TaxLiabilityCalculator::class)->forQuarter($profile, $year, $quarter);
                }

                foreach (TaxType::cases() as $type) {
                    $balances["$year-{$type->value}"] = app(TaxReconciler::class)->forYear($profile, $type, $year);
                }
            }

            return $this->withoutIds(json_decode(json_encode([
                'liabilities' => $liabilities,
                'reconciliation' => $balances,
                'accounts' => Account::query()->orderBy('id')->get()->map(fn (Account $account) => [
                    $account->name, app(AccountBalanceCalculator::class)->balance($account),
                ])->all(),
                'documents' => Invoice::query()->whereNotNull('snapshot')->orderBy('id')->get()
                    ->mapWithKeys(fn (Invoice $invoice) => [$invoice->number => md5(app(InvoicePdfRenderer::class)->html($invoice))])->all(),
            ]), true));
        });
    }

    /**
     * Connect to the other driver, when the tests run on SQLite: the MySQL suite's database, else skip the test (on
     * MySQL, the other is a SQLite file, which is always there).
     *
     * @param string $home The connection of the tests.
     *
     * @return void
     */
    private function prepareOtherDriver(string $home): void
    {
        if (DB::connection($home)->getDriverName() !== 'sqlite') {
            return;
        }

        config(['database.connections.other' => [...config('database.connections.mysql'), 'database' => self::MYSQL_DATABASE]]);

        try {
            DB::connection('other')->getPdo();
        } catch (PDOException $exception) {
            $this->markTestSkipped("MySQL can't be reached ({$exception->getMessage()}): run the tests on MySQL, or with its DB_HOST, DB_PORT, DB_USERNAME and DB_PASSWORD.");
        }
    }

    /**
     * Make the other driver's database the default connection, migrated and seeded: on SQLite, the MySQL suite's
     * database; on MySQL, a SQLite file.
     *
     * @param string $home The connection of the tests.
     *
     * @return void
     */
    private function useOtherDriver(string $home): void
    {
        if (DB::connection($home)->getDriverName() !== 'sqlite') {
            $this->migratedDatabase('other');

            return;
        }

        $this->useDatabase('other');
        Artisan::call('migrate:fresh', ['--database' => 'other', '--force' => true]);
        Artisan::call('db:seed', ['--database' => 'other', '--force' => true]);
    }

    /**
     * Make the tests' connection the default one again, keeping its database (an in-memory one is lost when purged)
     * and its transaction.
     *
     * @param string $home
     *
     * @return void
     */
    private function useHome(string $home): void
    {
        config(['database.default' => $home]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Cache::flush();
    }

    /**
     * Encrypt with another key, as another installation does, and hash the tax IDs with it.
     *
     * @param string $key
     *
     * @return void
     */
    private function useKey(string $key): void
    {
        config(['app.key' => $key]);
        app()->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');
    }

    /**
     * Remove the ids from figures, which the copy has of its own.
     *
     * @param array $figures
     *
     * @return array
     */
    private function withoutIds(array $figures): array
    {
        $result = [];

        foreach ($figures as $key => $value) {
            if (is_string($key) && ($key === 'id' || str_ends_with($key, '_id'))) {
                continue;
            }

            $result[$key] = is_array($value) ? $this->withoutIds($value) : $value;
        }

        return $result;
    }

    /**
     * Change the rows of a file of the archive, and its checksum in the manifest, so it passes the integrity check.
     *
     * @param string $archive
     * @param string $path
     * @param callable(array<string, string|null>): array<string, string|null> $change
     *
     * @return void
     */
    private function tamper(string $archive, string $path, callable $change): void
    {
        $rows = $this->archiveRows($archive, $path);
        $csv = Csv::BOM . Csv::line(array_keys($rows[0])) . implode('', array_map(fn (array $row) => Csv::line(array_values($change($row))), $rows));
        $manifest = json_decode((string) $this->archiveFile($archive, 'manifest.json'), true);

        foreach ($manifest['files'] as &$file) {
            if ($file['path'] === $path) {
                $file['sha256'] = hash('sha256', $csv);
            }
        }

        $zip = new ZipArchive;
        $zip->open($archive);
        $zip->addFromString($path, $csv);
        $zip->addFromString('manifest.json', json_encode($manifest));
        $zip->close();
    }
}
