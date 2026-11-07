<?php

namespace Tests\Feature\Http\Controllers;

use Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Class UserThemeControllerTest.
 *
 * The theme of the signed-in user's own screens, kept in their metadata, which `auth/me` returns.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Http\Controllers
 */
class UserThemeControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that a theme is kept on the user, `auth/me` returns it, and the rest of their metadata stays.
     *
     * @return void
     */
    public function testKeepsTheThemeOnTheUserWithTheRestOfTheirMetadata(): void
    {
        $user = User::factory()->create(['metadata' => ['note' => 'kept']]);

        $this->actingAs($user)
            ->patchJson('/api/auth/me/theme', ['theme' => 'dark'])
            ->assertOk()
            ->assertJsonPath('data.metadata.theme', 'dark');

        $this->actingAs($user)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.metadata', ['note' => 'kept', 'theme' => 'dark']);
    }

    /**
     * Test that a theme, which doesn't exist, is refused.
     *
     * @return void
     */
    public function testRefusesAnUnknownTheme(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patchJson('/api/auth/me/theme', ['theme' => 'sepia'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('theme');

        $this->assertNull($user->fresh()->metadata);
    }

    /**
     * Test that nobody but the signed-in user changes a theme.
     *
     * @return void
     */
    public function testAsksGuestsToSignIn(): void
    {
        $this->patchJson('/api/auth/me/theme', ['theme' => 'dark'])->assertUnauthorized();
    }

    /**
     * Test that a user, who still has to replace a password someone else set, may change their theme on that screen.
     *
     * @return void
     */
    public function testLetsAUserChangeItBeforeReplacingAPasswordSomeoneElseSet(): void
    {
        $user = User::factory()->create(['password_updated_at' => null]);

        $this->actingAs($user)
            ->patchJson('/api/auth/me/theme', ['theme' => 'light'])
            ->assertOk()
            ->assertJsonPath('data.metadata.theme', 'light');
    }
}
