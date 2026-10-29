<?php

namespace Tests\Feature\Services\Desktop;

use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use PDO;
use PryTax\Services\Desktop\DatabaseRestore;
use PryTax\Services\Desktop\InstallationKey;
use Tests\TestCase;

/**
 * Class DatabaseRestoreTest.
 *
 * Restoring the desktop app on a new computer (APP-BUILDS-PLAN.md, phase 3): a recovery kit's key, which must open the
 * old database, checked before the database and the key replace the new installation's.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Services\Desktop
 */
class DatabaseRestoreTest extends TestCase
{
    /**
     * A directory of the test's own.
     *
     * @var string
     */
    protected string $directory;

    /**
     * Set up a directory of the test's own.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir() . '/prytax-restore-' . bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->directory);
    }

    /**
     * Remove the test's directory.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    /**
     * Test that a kit's key is found in its text, and a file without one is refused.
     *
     * @return void
     */
    public function testReadsTheKeyOfARecoveryKit(): void
    {
        $key = $this->key('a');
        file_put_contents("$this->directory/kit.txt", "PryTax recovery kit\n\nKey: $key\n");
        file_put_contents("$this->directory/notes.txt", 'Groceries');

        $this->assertSame($key, $this->restore()->keyOf("$this->directory/kit.txt"));
        $this->expectException(ValidationException::class);

        $this->restore()->keyOf("$this->directory/notes.txt");
    }

    /**
     * Test that the kit's key must decrypt the database's tax IDs.
     *
     * @return void
     */
    public function testRefusesAKeyWhichDoesntOpenTheDatabase(): void
    {
        $this->database("$this->directory/old.sqlite", $this->key('a'));

        $this->restore()->verify("$this->directory/old.sqlite", $this->key('a'));

        try {
            $this->restore()->verify("$this->directory/old.sqlite", $this->key('b'));
            $this->fail('A key, which doesn\'t open the database, passed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('kit', $exception->errors());
        }
    }

    /**
     * Test that a file, which isn't a PryTax database, is refused.
     *
     * @return void
     */
    public function testRefusesAFileWhichIsntAPryTaxDatabase(): void
    {
        file_put_contents("$this->directory/photo.sqlite", 'not a database');

        try {
            $this->restore()->verify("$this->directory/photo.sqlite", $this->key('a'));
            $this->fail('A file, which isn\'t a database, passed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('database', $exception->errors());
        }
    }

    /**
     * Test that the database and its key replace the installation's, without the old write-ahead log.
     *
     * @return void
     */
    public function testInstallsTheDatabaseAndItsKey(): void
    {
        $this->database("$this->directory/old.sqlite", $this->key('a'));
        file_put_contents("$this->directory/database.sqlite", 'the new installation');
        file_put_contents("$this->directory/database.sqlite-wal", 'its log');
        file_put_contents("$this->directory/database.sqlite.key", $this->key('n'));

        $this->restore()->install("$this->directory/old.sqlite", $this->key('a'));

        $this->assertFileEquals("$this->directory/old.sqlite", "$this->directory/database.sqlite");
        $this->assertFileDoesNotExist("$this->directory/database.sqlite-wal");
        $this->assertSame($this->key('a'), file_get_contents("$this->directory/database.sqlite.key"));
        $this->assertNotNull((new InstallationKey("$this->directory/database.sqlite"))->recoveryKitSavedAt());
    }

    /**
     * Get the restore of an installation in the test's directory.
     *
     * @return DatabaseRestore
     */
    protected function restore(): DatabaseRestore
    {
        return new DatabaseRestore(new InstallationKey("$this->directory/database.sqlite"), "$this->directory/database.sqlite");
    }

    /**
     * Get a key of the letter.
     *
     * @param string $letter
     *
     * @return string
     */
    protected function key(string $letter): string
    {
        return 'base64:' . base64_encode(str_repeat($letter, 32));
    }

    /**
     * Make a database with PryTax's tables and a tax ID, which the key encrypted.
     *
     * @param string $path
     * @param string $key
     *
     * @return void
     */
    protected function database(string $path, string $key): void
    {
        $db = new PDO("sqlite:$path");

        foreach (['migrations', 'organizations', 'users'] as $table) {
            $db->exec("CREATE TABLE $table (id INTEGER PRIMARY KEY)");
        }

        $db->exec('CREATE TABLE profiles (id INTEGER PRIMARY KEY, tax_id TEXT)');
        $taxId = (new Encrypter(base64_decode(substr($key, 7)), config('app.cipher')))->encryptString('1234567890');
        $db->prepare('INSERT INTO profiles (tax_id) VALUES (?)')->execute([$taxId]);
    }
}
