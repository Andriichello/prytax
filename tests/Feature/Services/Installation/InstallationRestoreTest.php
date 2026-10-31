<?php

namespace Tests\Feature\Services\Installation;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PDO;
use PryTax\Models\Profile;
use PryTax\Services\Installation\InstallationArchive;
use PryTax\Services\Installation\InstallationBackup;
use PryTax\Services\Installation\InstallationComparison;
use PryTax\Services\Installation\InstallationRestore;
use Tests\Concerns\ActsInProfiles;
use Tests\Concerns\MakesInstallationDatabases;
use Tests\TestCase;
use ZipArchive;

/**
 * Class InstallationRestoreTest.
 *
 * A full backup of the installation and its restore (builds.md §4.11): the database, its key, the documents and the
 * in-app backups (not their temporary files) in a zip only the password opens; a restored database encrypted again
 * with this installation's key, without the other computer's sessions; and the backup put in place of this
 * installation, its files in place of these.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Services\Installation
 */
class InstallationRestoreTest extends TestCase
{
    use ActsInProfiles;
    use MakesInstallationDatabases;

    /**
     * Test that a backup holds the database, the key, the documents and the in-app backups, and that only its
     * password opens it.
     *
     * @return void
     */
    public function testWritesABackupOfTheDatabaseKeyDocumentsAndArchivesUnderItsPassword(): void
    {
        $file = $this->smallDatabase(['organizations' => [['id' => 1, 'name' => 'Практика Коваленко']], 'profiles' => [['id' => 1, 'organization_id' => 1, 'full_name' => 'Коваленко Олена Петрівна']]]);
        config(['database.connections.installation' => [...config('database.connections.sqlite'), 'database' => $file]]);
        $this->useDatabase('installation');
        Storage::fake('local')->put('files/invoices/1/invoice.pdf', 'PDF');
        Storage::disk('backups')->put('organization-1/backup.zip', 'ZIP');
        Storage::disk('backups')->put('tmp/bundles/bundle.zip', 'TEMPORARY');

        $path = app(InstallationBackup::class)->write('correct-horse-battery');

        $zip = new ZipArchive;
        $zip->open($path);
        $this->assertFalse($zip->getFromName('manifest.json'));
        $zip->close();

        try {
            InstallationArchive::extract($path, 'wrong-horse-battery', $this->directory());
            $this->fail('A wrong password opened the backup.');
        } catch (ValidationException $exception) {
            $this->assertSame(['password'], array_keys($exception->errors()));
        }

        $archive = InstallationArchive::extract($path, 'correct-horse-battery', $directory = $this->directory());

        $this->assertSame(config('app.key'), $archive->key);
        $this->assertSame([['id' => 1, 'name' => 'Практика Коваленко', 'profiles' => 1]], $archive->manifest['organizations']);
        $this->assertSame([1, 1], [$archive->manifest['documents']['count'], $archive->manifest['archives']['count']]);
        $this->assertSame('Коваленко Олена Петрівна', InstallationComparison::open($archive->databasePath())->query('SELECT full_name FROM profiles')->fetchColumn());
        $this->assertSame('PDF', file_get_contents("$directory/documents/files/invoices/1/invoice.pdf"));
        $this->assertSame('ZIP', file_get_contents("$directory/archives/organization-1/backup.zip"));
        $this->assertDirectoryDoesNotExist("$directory/archives/tmp");
    }

    /**
     * Test that a restored database is encrypted again with this installation's key, its tax IDs hashed with it,
     * and the other computer's sessions are gone.
     *
     * @return void
     */
    public function testPreparesARestoredDatabaseForThisInstallationsKey(): void
    {
        $key = $this->makeKey();
        $file = $this->smallDatabase([
            'profiles' => [['id' => 1, 'organization_id' => 1, 'full_name' => 'Коваленко Олена Петрівна', 'tax_id' => $this->encryptWith($key, '3214704821'), 'tax_id_hash' => hash_hmac('sha256', '3214704821', $key)]],
            'sessions' => [['id' => 'abc', 'payload' => 'another computer']],
        ]);

        app(InstallationRestore::class)->prepare($file, $key);

        $db = InstallationComparison::open($file);
        $profile = $db->query('SELECT tax_id, tax_id_hash FROM profiles')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('3214704821', decrypt($profile['tax_id'], false));
        $this->assertSame(Profile::hashTaxId('3214704821'), $profile['tax_id_hash']);
        $this->assertSame(0, (int) $db->query('SELECT COUNT(*) FROM sessions')->fetchColumn());
    }

    /**
     * Test that the backup takes this installation's place: its ФОПs instead of these, and its documents and in-app
     * backups instead of these, but for the temporary files.
     *
     * @return void
     */
    public function testRestoresTheBackupInPlaceOfThisInstallation(): void
    {
        Storage::fake('local');
        $this->migratedDatabase('first');
        $this->createProfile(attributes: ['full_name' => 'Ткаченко Ірина Петрівна']);
        Storage::disk('local')->put('files/first.pdf', 'FIRST');
        Storage::disk('backups')->put('first/backup.zip', 'FIRST');
        $archive = InstallationArchive::extract(app(InstallationBackup::class)->write('correct-horse-battery'), 'correct-horse-battery', $this->directory());

        $second = $this->migratedDatabase('second');
        $this->createProfile(attributes: ['full_name' => 'Мельник Андрій Іванович']);
        Storage::disk('local')->delete('files/first.pdf');
        Storage::disk('local')->put('files/second.pdf', 'SECOND');
        Storage::disk('backups')->delete('first/backup.zip');
        Storage::disk('backups')->put('second/backup.zip', 'SECOND');
        Storage::disk('backups')->put('tmp/exports/export.zip', 'TEMPORARY');

        app(InstallationRestore::class)->install($archive);

        $this->assertSame($second, config('database.connections.second.database'));
        $this->assertSame(['Ткаченко Ірина Петрівна'], Profile::query()->withoutGlobalScopes()->pluck('full_name')->all());
        $this->assertSame(['files/first.pdf'], Storage::disk('local')->allFiles('files'));
        $this->assertEqualsCanonicalizing(['first/backup.zip', 'tmp/exports/export.zip'], Storage::disk('backups')->allFiles());
    }

    /**
     * Get a new directory, which is removed after the test.
     *
     * @return string
     */
    private function directory(): string
    {
        $directory = sys_get_temp_dir() . '/prytax-installation-' . Str::random(8);
        File::ensureDirectoryExists($directory);
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($directory));

        return $directory;
    }
}
