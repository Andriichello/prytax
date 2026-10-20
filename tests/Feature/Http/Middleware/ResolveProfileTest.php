<?php

namespace Tests\Feature\Http\Middleware;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;

/**
 * Class ResolveProfileTest.
 *
 * The profile of `profiles/{profile}/…` is found within the user's organization, so another organization's profile,
 * or its records, are a 404 that confirms nothing.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Http\Middleware
 */
class ResolveProfileTest extends TestCase
{
    use ActsInProfiles;
    use RefreshDatabase;

    /**
     * Test that a profile of another organization answers a plain 404, as outside debug mode.
     *
     * @return void
     */
    public function testReturns404ForAProfileOfAnotherOrganization(): void
    {
        config(['app.debug' => false]);
        $mine = $this->createProfile();
        $foreign = $this->createProfile();
        $this->actAs($mine);

        $this->getJson($this->profilePath($foreign, 'accounts'))
            ->assertNotFound()
            ->assertJsonPath('message', 'Not found.');
    }

    /**
     * Test that a record of another profile of the same organization answers 404 under this one.
     *
     * @return void
     */
    public function testReturns404ForARecordOfAnotherProfile(): void
    {
        $mine = $this->createProfile();
        $other = $this->createProfile($mine->organization);
        $account = $this->createAccount($other);
        $this->actAs($mine);

        $this->getJson($this->profilePath($mine, "accounts/{$account->id}"))->assertNotFound();
        $this->getJson($this->profilePath($other, "accounts/{$account->id}"))->assertOk()->assertJsonPath('data.id', $account->id);
    }

    /**
     * Test that a request without a session or token answers 401.
     *
     * @return void
     */
    public function testReturns401WithoutAuthentication(): void
    {
        $profile = $this->createProfile();

        $this->getJson($this->profilePath($profile, 'accounts'))->assertUnauthorized();
    }
}
