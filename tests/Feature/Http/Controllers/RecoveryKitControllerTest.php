<?php

namespace Tests\Feature\Http\Controllers;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;

/**
 * Class RecoveryKitControllerTest.
 *
 * The desktop installation's recovery kit (builds.md §4.5): only for an owner in the desktop
 * app; saved through a native dialog, or copied on the owner's word.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Http\Controllers
 */
class RecoveryKitControllerTest extends TestCase
{
    use ActsInProfiles;
    use RefreshDatabase;

    /**
     * A directory of the test's own: the desktop's database and Documents.
     *
     * @var string
     */
    protected string $directory;

    /**
     * Run the test as the desktop app, whose database and Documents are in the test's directory.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir() . '/prytax-kit-' . bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->directory);
        config([
            'app.key' => 'base64:' . base64_encode(str_repeat('k', 32)),
            'app.debug' => false,
            'nativephp-internal.running' => true,
            'nativephp-internal.database_path' => "$this->directory/database.sqlite",
            'filesystems.disks.documents.root' => $this->directory,
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
     * Test that the kit is a 404 outside the desktop app, and a 403 for anyone but an owner.
     *
     * @return void
     */
    public function testShowsTheKitOnlyToAnOwnerInTheDesktopApp(): void
    {
        $profile = $this->createProfile();
        $this->actAs($profile, 'accountant');

        $this->getJson('/api/desktop/recovery-kit')->assertForbidden();

        config(['nativephp-internal.running' => false]);
        $this->actAs($profile);

        $this->getJson('/api/desktop/recovery-kit')->assertNotFound();
    }

    /**
     * Test that the kit carries the installation's key and its organizations, one a line, and is unkept at first.
     *
     * @return void
     */
    public function testShowsTheKitWithTheKey(): void
    {
        $profile = $this->createProfile();
        $this->actAs($profile);

        $response = $this->getJson('/api/desktop/recovery-kit');

        $response->assertOk()->assertJsonPath('data.saved_at', null);
        $this->assertStringContainsString('base64:' . base64_encode(str_repeat('k', 32)), $response->json('data.text'));
        $this->assertStringContainsString("Organizations:\n- {$profile->organization->name}\n", $response->json('data.text'));
        $this->assertStringStartsWith('PryTax-recovery-kit-', $response->json('data.filename'));
    }

    /**
     * Test that the kit is saved where the native dialog says, and kept from then on.
     *
     * @return void
     */
    public function testSavesTheKitWhereTheNativeDialogSays(): void
    {
        $this->actAs($this->createProfile());
        Http::fake(['*/dialog/save' => Http::response(['result' => "$this->directory/kit.txt"])]);

        $response = $this->postJson('/api/desktop/recovery-kit/save');

        $response->assertOk()->assertJsonPath('data.path', "$this->directory/kit.txt");
        $this->assertNotNull($response->json('data.saved_at'));
        $this->assertSame($response->json('data.text'), file_get_contents("$this->directory/kit.txt"));
        Http::assertSent(fn ($request) => str_ends_with($request['defaultPath'], $response->json('data.filename')));
    }

    /**
     * Test that a cancelled dialog saves nothing and leaves the kit unkept.
     *
     * @return void
     */
    public function testKeepsNothingWhenTheDialogIsCancelled(): void
    {
        $this->actAs($this->createProfile());
        Http::fake(['*/dialog/save' => Http::response(['result' => null])]);

        $response = $this->postJson('/api/desktop/recovery-kit/save');

        $response->assertOk()->assertJsonPath('data.path', null)->assertJsonPath('data.saved_at', null);
    }

    /**
     * Test that the owner's word that they copied the kit keeps it.
     *
     * @return void
     */
    public function testTakesTheOwnersWordThatTheyCopiedTheKit(): void
    {
        $this->actAs($this->createProfile());

        $this->postJson('/api/desktop/recovery-kit/confirm')->assertOk();

        $this->assertNotNull($this->getJson('/api/desktop/recovery-kit')->json('data.saved_at'));
    }
}
