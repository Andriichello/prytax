<?php

namespace Tests\Feature\Services\Desktop;

use Illuminate\Support\Facades\File;
use PryTax\Services\Desktop\InstallationKey;
use Tests\TestCase;

/**
 * Class InstallationKeyTest.
 *
 * The desktop installation's own key (APP-BUILDS-PLAN.md, phase 3, step 5): made on the first launch, kept beside the
 * database for the user only, and the same from then on; the bundled one only while developing or for data it
 * encrypted.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Services\Desktop
 */
class InstallationKeyTest extends TestCase
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

        $this->directory = sys_get_temp_dir() . '/prytax-key-' . bin2hex(random_bytes(6));
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
     * Test that a new installation makes a key of its own, readable by the user only, and keeps it.
     *
     * @return void
     */
    public function testMakesAKeyOfItsOwnOnTheFirstLaunchAndKeepsIt(): void
    {
        config(['app.debug' => false]);
        touch("$this->directory/database.sqlite");
        $key = new InstallationKey("$this->directory/database.sqlite");

        $first = $key->resolve('base64:' . base64_encode(str_repeat('b', 32)));

        $this->assertMatchesRegularExpression('/^base64:[A-Za-z0-9+\/]{43}=$/', $first);
        $this->assertNotSame('base64:' . base64_encode(str_repeat('b', 32)), $first);
        $this->assertSame($first, file_get_contents("$this->directory/database.sqlite.key"));
        $this->assertSame('0600', substr(sprintf('%o', fileperms("$this->directory/database.sqlite.key")), -4));
        $this->assertSame($first, (new InstallationKey("$this->directory/database.sqlite"))->resolve(null));
    }

    /**
     * Test that the bundled key is kept while developing, and for a database, which it encrypted.
     *
     * @return void
     */
    public function testKeepsTheBundledKeyWhileDevelopingAndForDataItEncrypted(): void
    {
        $bundled = 'base64:' . base64_encode(str_repeat('b', 32));
        config(['app.debug' => true]);

        $this->assertSame($bundled, (new InstallationKey("$this->directory/fresh.sqlite"))->resolve($bundled));

        config(['app.debug' => false]);
        file_put_contents("$this->directory/old.sqlite", 'SQLite format 3');

        $this->assertSame($bundled, (new InstallationKey("$this->directory/old.sqlite"))->resolve($bundled));
    }

    /**
     * Test that the recovery kit is unkept, until the owner keeps it.
     *
     * @return void
     */
    public function testRecordsWhenTheRecoveryKitWasKept(): void
    {
        $this->travelTo('2026-10-29 21:00:00');
        $key = new InstallationKey("$this->directory/database.sqlite");

        $this->assertNull($key->recoveryKitSavedAt());

        $key->markRecoveryKitSaved('copy');

        $this->assertSame('2026-10-29 21:00:00', $key->recoveryKitSavedAt()?->format('Y-m-d H:i:s'));
    }
}
