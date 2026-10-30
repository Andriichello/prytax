<?php

namespace Tests\Feature\Http\Controllers;

use Core\Models\User;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PDO;
use Tests\TestCase;

/**
 * Class DesktopRestoreControllerTest.
 *
 * Restoring the desktop app on a new computer from its first screen (builds.md §4.6): only in the
 * desktop app, and only before anyone registered; the files come from native dialogs, and a restore relaunches it.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Http\Controllers
 */
class DesktopRestoreControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A directory of the test's own: the desktop's database, and the old computer's files.
     *
     * @var string
     */
    protected string $directory;

    /**
     * Run the test as the desktop app, whose database is in the test's directory.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir() . '/prytax-restore-' . bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->directory);
        config([
            'app.debug' => false,
            'nativephp-internal.running' => true,
            'nativephp-internal.database_path' => "$this->directory/database.sqlite",
        ]);
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
     * Test that restoring is a 404 outside the desktop app, and a 409 once someone registered.
     *
     * @return void
     */
    public function testRestoresOnlyANewDesktopInstallation(): void
    {
        User::factory()->create();

        $this->postJson('/api/desktop/restore/kit')->assertConflict();

        config(['nativephp-internal.running' => false]);

        $this->postJson('/api/desktop/restore/kit')->assertNotFound();
    }

    /**
     * Test that the kit chosen in the native dialog must be a recovery kit.
     *
     * @return void
     */
    public function testChoosesTheKitInANativeDialog(): void
    {
        file_put_contents("$this->directory/kit.txt", 'Key: ' . $this->key('a'));
        file_put_contents("$this->directory/notes.txt", 'Groceries');
        Http::fake(['*/dialog/open' => Http::sequence()
            ->push(['result' => ["$this->directory/kit.txt"]])
            ->push(['result' => ["$this->directory/notes.txt"]])]);

        $this->postJson('/api/desktop/restore/kit')->assertOk()->assertJsonPath('data.name', 'kit.txt');
        $this->postJson('/api/desktop/restore/kit')->assertUnprocessable()->assertJsonValidationErrors(['kit']);
    }

    /**
     * Test that a restore installs the database and the kit's key, and relaunches the app.
     *
     * @return void
     */
    public function testRestoresTheDatabaseAndRelaunches(): void
    {
        Http::fake();
        file_put_contents("$this->directory/kit.txt", 'Key: ' . $this->key('a'));
        $this->database("$this->directory/old.sqlite", $this->key('a'));

        $response = $this->postJson('/api/desktop/restore', ['kit' => "$this->directory/kit.txt", 'database' => "$this->directory/old.sqlite"]);

        $response->assertOk()->assertJsonPath('data.restored', true);
        $this->assertFileEquals("$this->directory/old.sqlite", "$this->directory/database.sqlite");
        $this->assertSame($this->key('a'), file_get_contents("$this->directory/database.sqlite.key"));
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/app/relaunch'));
    }

    /**
     * Test that a kit of another computer changes nothing.
     *
     * @return void
     */
    public function testRefusesTheKitOfAnotherComputer(): void
    {
        Http::fake();
        file_put_contents("$this->directory/kit.txt", 'Key: ' . $this->key('b'));
        $this->database("$this->directory/old.sqlite", $this->key('a'));

        $response = $this->postJson('/api/desktop/restore', ['kit' => "$this->directory/kit.txt", 'database' => "$this->directory/old.sqlite"]);

        $response->assertUnprocessable()->assertJsonValidationErrors(['kit']);
        $this->assertFileDoesNotExist("$this->directory/database.sqlite");
        Http::assertNothingSent();
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
