<?php

namespace Tests\Feature\Http\Controllers;

use Core\Models\Organization;
use Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PryTax\Models\RecoveryCode;
use PryTax\Services\Setup\SingleOrganization;
use Tests\TestCase;

/**
 * Class SingleOrganizationControllerTest.
 *
 * One person's installation (builds.md §1.4): its only organization registered with a password alone, with a made-up
 * name, e-mail and a recovery code; the password alone signing in while it has one user; a new password from the
 * recovery code or the installation's key; and the recovery kit outside the desktop app, with the code and without
 * the key.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Http\Controllers
 */
class SingleOrganizationControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Turn one person's installation and registration on.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['prytax.single_organization' => true, 'prytax.registration' => true]);
    }

    /**
     * Test that the only organization is registered with a password alone, its name and e-mail made up and a recovery
     * code given, and that no second one is.
     *
     * @return void
     */
    public function testRegistersTheOnlyOrganizationWithAPasswordAlone(): void
    {
        $this->getJson('/api/registration')->assertOk()
            ->assertJsonPath('data', ['enabled' => true, 'first_owner' => true, 'single_organization' => true, 'password_only' => false]);

        $this->postJson('/api/registration', ['password' => 'correct-horse-battery', 'password_confirmation' => 'correct-horse-battery'])->assertCreated();

        $user = User::query()->sole();
        $this->assertMatchesRegularExpression('/^prytax\+[a-z]{3}-[a-z]{3}-[a-z]{3}$/', Organization::query()->sole()->name);
        $this->assertMatchesRegularExpression('/^prytax\+[a-z0-9]{6}@prytax\.invalid$/', $user->email);
        $this->assertTrue($user->hasRole('owner'));
        $this->assertMatchesRegularExpression('/^[2-9A-HJKMNP-Z]{5}(-[2-9A-HJKMNP-Z]{5}){3}$/', RecoveryCode::query()->sole()->code);
        $this->getJson('/api/registration')->assertOk()->assertJsonPath('data.enabled', false)->assertJsonPath('data.password_only', true);
        $this->postJson('/api/registration', ['password' => 'correct-horse-battery', 'password_confirmation' => 'correct-horse-battery'])->assertNotFound();
    }

    /**
     * Test that the password alone signs in while the installation has one user, and not with a wrong one or once
     * there are two.
     *
     * @return void
     */
    public function testSignsInWithThePasswordAloneWhileThereIsOneUser(): void
    {
        $owner = app(SingleOrganization::class)->register('correct-horse-battery');

        $this->postJson('/api/single/login', ['password' => 'wrong-horse-battery'])->assertUnprocessable()->assertJsonValidationErrors(['password' => __('The password isn\'t right.')]);
        $this->postJson('/api/single/login', ['password' => 'correct-horse-battery'])->assertOk()->assertJsonPath('data.user.id', $owner->id);

        User::factory()->create(['organization_id' => $owner->organization_id]);

        $this->getJson('/api/registration')->assertOk()->assertJsonPath('data.password_only', false);
        $this->postJson('/api/single/login', ['password' => 'correct-horse-battery'])->assertNotFound();
    }

    /**
     * Test that the recovery code, typed in any case and with spaces, or the installation's key, sets a new password,
     * and that anything else doesn't.
     *
     * @return void
     */
    public function testSetsANewPasswordWithTheRecoveryCodeOrTheKey(): void
    {
        $single = app(SingleOrganization::class);
        $owner = $single->register('correct-horse-battery');
        $code = $single->codeOf($owner)->code;
        $reset = fn (string $secret, string $password) => $this->postJson('/api/single/password', ['secret' => $secret, 'password' => $password, 'password_confirmation' => $password]);

        $reset('WRONG-CODE0-00000-00000', 'brand-new-battery')->assertUnprocessable()->assertJsonValidationErrors('secret');
        $reset(strtolower(str_replace('-', ' ', $code)), 'brand-new-battery')->assertOk()->assertJsonPath('data.user.id', $owner->id);
        $this->assertTrue(Hash::check('brand-new-battery', $owner->fresh()->password));

        $reset((string) config('app.key'), 'another-new-battery')->assertOk();
        $this->assertTrue(Hash::check('another-new-battery', $owner->fresh()->password));
    }

    /**
     * Test that one person's web installation shows its owner the recovery kit with the code and without the key, and
     * records that it was downloaded.
     *
     * @return void
     */
    public function testShowsTheRecoveryKitWithTheCodeOutsideTheDesktopApp(): void
    {
        $single = app(SingleOrganization::class);
        $owner = $single->register('correct-horse-battery');
        $this->actingAs($owner);

        $kit = $this->getJson('/api/desktop/recovery-kit')->assertOk()->assertJsonPath('data.saved_at', null)->json('data.text');

        $this->assertStringContainsString($single->codeOf($owner)->code, $kit);
        $this->assertStringNotContainsString((string) config('app.key'), $kit);
        $this->postJson('/api/desktop/recovery-kit/save')->assertNotFound();
        $this->postJson('/api/desktop/recovery-kit/confirm', ['via' => 'file'])->assertOk()->assertJsonPath('data.saved_at', fn ($at) => $at !== null);

        config(['prytax.single_organization' => false]);
        $this->getJson('/api/desktop/recovery-kit')->assertNotFound();
    }
}
