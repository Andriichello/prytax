<?php

namespace Tests\Feature\Http\Controllers;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PryTax\Models\Profile;
use PryTax\Services\Installation\InstallationBackup;
use Tests\Concerns\ActsInProfiles;
use Tests\Concerns\MakesInstallationDatabases;
use Tests\TestCase;

/**
 * Class InstallationBackupControllerTest.
 *
 * Full backups of the installation over the API (builds.md §4.11): not offered where `prytax.full_backups` is off,
 * only for owners (or anyone before the first user registered), and a restore of a backup uploaded in pieces, compared
 * first, which waits until this installation's own state was saved.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Http\Controllers
 */
class InstallationBackupControllerTest extends TestCase
{
    use ActsInProfiles;
    use MakesInstallationDatabases;

    /**
     * Turn full backups on, and fake the files' disk.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['prytax.full_backups' => true]);
        Storage::fake('local');
    }

    /**
     * Test that full backups aren't offered where they're off (a server).
     *
     * @return void
     */
    public function testIsntOfferedWhereFullBackupsAreOff(): void
    {
        $this->migratedDatabase('installation');
        config(['prytax.full_backups' => false]);

        $this->getJson('/api/installation')->assertOk()->assertJsonPath('data.enabled', false)->assertJsonPath('data.allowed', false);
        $this->postJson('/api/installation/uploads')->assertNotFound();
    }

    /**
     * Test that an accountant can't back up or restore the installation, and that anyone can before the first user
     * registered (a new computer's first launch).
     *
     * @return void
     */
    public function testLetsOnlyOwnersOrAnyoneBeforeTheFirstUser(): void
    {
        $this->migratedDatabase('installation');
        $this->postJson('/api/installation/uploads')->assertOk();

        Sanctum::actingAs($this->userOf($this->createProfile()->organization, 'accountant'));

        $this->postJson('/api/installation/uploads')->assertForbidden();
        $this->postJson('/api/installation/backups', ['password' => 'correct-horse-battery', 'password_confirmation' => 'correct-horse-battery'])->assertForbidden();
    }

    /**
     * Test that a backup uploaded in pieces is compared with this installation, and restored only once this
     * installation's own state was saved, taking its place.
     *
     * @return void
     */
    public function testComparesAnUploadedBackupAndRestoresItOnlyOnceThisStateIsSaved(): void
    {
        $this->travelTo('2026-10-20 12:00:00');
        $this->migratedDatabase('first');
        $this->createProfile(attributes: ['full_name' => 'Ткаченко Ірина Петрівна']);
        $backup = (string) file_get_contents(app(InstallationBackup::class)->write('correct-horse-battery'));

        $this->travelTo('2026-10-25 12:00:00');
        $second = $this->migratedDatabase('second');
        Sanctum::actingAs($this->userOf($this->createProfile(attributes: ['full_name' => 'Мельник Андрій Іванович'])->organization));
        $upload = $this->postJson('/api/installation/uploads')->assertOk()->json('data.id');
        [$first, $rest] = str_split($backup, intdiv(strlen($backup), 2) + 1);
        $piece = fn (string $bytes, int $offset) => $this->post("/api/installation/uploads/$upload", ['piece' => UploadedFile::fake()->createWithContent('piece', $bytes), 'offset' => $offset], ['Accept' => 'application/json']);

        $piece($first, 0)->assertOk();
        $piece($rest, 0)->assertStatus(409)->assertJsonPath('data.size', strlen($first));
        $piece($rest, strlen($first))->assertOk()->assertJsonPath('data.size', strlen($backup));

        $comparison = $this->postJson('/api/installation/restores', ['upload' => $upload, 'password' => 'correct-horse-battery'])
            ->assertOk()
            ->assertJsonPath('data.verdict', 'rewrite')
            ->assertJsonPath('data.needs_current_backup', true)
            ->assertJsonPath('data.backup.organizations.0.profiles', 1);
        $token = $comparison->json('data.token');

        $this->postJson("/api/installation/restores/$token")->assertUnprocessable()->assertJsonValidationErrors('current_backup');

        $link = $this->postJson('/api/installation/backups', ['password' => 'other-horse-battery', 'password_confirmation' => 'other-horse-battery', 'restore' => $token])
            ->assertOk()
            ->json('data.download_url');
        $this->get($link)->assertOk()->assertDownload();

        $this->postJson("/api/installation/restores/$token")->assertOk()->assertJsonPath('data.restored', true);

        $this->assertSame($second, config('database.connections.second.database'));
        $this->assertSame(['Ткаченко Ірина Петрівна'], Profile::query()->withoutGlobalScopes()->pluck('full_name')->all());
    }
}
