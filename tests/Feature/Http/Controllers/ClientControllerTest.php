<?php

namespace Tests\Feature\Http\Controllers;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PryTax\Models\Client;
use PryTax\Models\Profile;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;

/**
 * Class ClientControllerTest.
 *
 * The abbreviation of a client, which prefixes its invoice numbers (design.md §5): required, upper-case Latin letters
 * and digits up to 10, typed in any case, and unique among the clients of the profile, inactive ones included, while a
 * client of another profile may have the same.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Http\Controllers
 */
class ClientControllerTest extends TestCase
{
    use ActsInProfiles;
    use RefreshDatabase;

    /**
     * Abbreviations, which aren't up to 10 Latin letters and digits, with the error each gets.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function invalidAbbreviations(): array
    {
        return [
            'Cyrillic letters' => ['БЛ', 'The abbreviation field format is invalid.'],
            'a hyphen' => ['B-L', 'The abbreviation field format is invalid.'],
            'eleven characters' => ['ABCDEFGHIJK', 'The abbreviation field must not be greater than 10 characters.'],
        ];
    }

    /**
     * Test that a new client keeps its abbreviation in upper case, however it was typed.
     *
     * @return void
     */
    public function testCreatesAClientWithItsAbbreviationInUpperCase(): void
    {
        $profile = $this->createProfile();
        $this->actAs($profile);

        $response = $this->postJson($this->profilePath($profile, 'clients'), ['name' => 'Brightline Labs LLC', 'abbreviation' => 'bl2']);

        $response->assertCreated()->assertJsonPath('data.abbreviation', 'BL2');
        $this->assertDatabaseHas('clients', ['profile_id' => $profile->id, 'name' => 'Brightline Labs LLC', 'abbreviation' => 'BL2']);
    }

    /**
     * Test that a new client without an abbreviation is refused with 422.
     *
     * @return void
     */
    public function testReturns422ForAClientWithoutAnAbbreviation(): void
    {
        $profile = $this->createProfile();
        $this->actAs($profile);

        $this->postJson($this->profilePath($profile, 'clients'), ['name' => 'Brightline Labs LLC'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['abbreviation' => 'The abbreviation field is required.']);
    }

    /**
     * Test that an abbreviation of other characters than Latin letters and digits, or longer than 10, is refused.
     *
     * @param string $abbreviation
     * @param string $error
     *
     * @return void
     */
    #[DataProvider('invalidAbbreviations')]
    public function testReturns422ForAnAbbreviationOfOtherCharacters(string $abbreviation, string $error): void
    {
        $profile = $this->createProfile();
        $this->actAs($profile);

        $this->postJson($this->profilePath($profile, 'clients'), ['name' => 'Brightline Labs LLC', 'abbreviation' => $abbreviation])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['abbreviation' => $error]);
    }

    /**
     * Test that an abbreviation of another client of the profile, an inactive one too, is refused in any case, with a
     * Ukrainian message for the SPA.
     *
     * @return void
     */
    public function testReturns422ForAnAbbreviationAnInactiveClientOfTheProfileHas(): void
    {
        $profile = $this->createProfile();
        $this->actAs($profile);
        $this->createClient($profile, 'BL', inactive: true);
        $client = ['name' => 'Bluebird Labs', 'abbreviation' => 'bl'];

        $this->postJson($this->profilePath($profile, 'clients'), $client)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['abbreviation' => 'The abbreviation has already been taken.']);
        $this->postJson($this->profilePath($profile, 'clients'), $client, ['Accept-Language' => 'uk'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['abbreviation' => 'Ця абревіатура вже є в іншого клієнта.']);
    }

    /**
     * Test that a client of another profile doesn't take the abbreviation.
     *
     * @return void
     */
    public function testCreatesAClientWithTheAbbreviationOfAnotherProfilesClient(): void
    {
        $profile = $this->createProfile();
        $this->actAs($profile);
        $this->createClient($this->createProfile($profile->organization), 'BL');

        $this->postJson($this->profilePath($profile, 'clients'), ['name' => 'Brightline Labs LLC', 'abbreviation' => 'BL'])
            ->assertCreated()
            ->assertJsonPath('data.abbreviation', 'BL');
    }

    /**
     * Test that a client keeps its own abbreviation, when it is sent with an update, but can't take another's.
     *
     * @return void
     */
    public function testUpdatesAClientWithItsOwnAbbreviationButNotAnothersOne(): void
    {
        $profile = $this->createProfile();
        $this->actAs($profile);
        $client = $this->createClient($profile, 'BL');
        $this->createClient($profile, 'NW');
        $path = $this->profilePath($profile, "clients/{$client->id}");

        $this->patchJson($path, ['name' => 'Brightline Labs', 'abbreviation' => 'BL'])->assertOk();
        $this->patchJson($path, ['abbreviation' => 'nw'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['abbreviation' => 'The abbreviation has already been taken.']);

        $this->assertDatabaseHas('clients', ['id' => $client->id, 'name' => 'Brightline Labs', 'abbreviation' => 'BL']);
    }

    /**
     * Create a client of the profile with the abbreviation.
     *
     * @param Profile $profile
     * @param string $abbreviation
     * @param bool $inactive
     *
     * @return Client
     */
    private function createClient(Profile $profile, string $abbreviation, bool $inactive = false): Client
    {
        $factory = $inactive ? Client::factory()->inactive() : Client::factory();

        return $this->inProfile($profile, fn () => $factory->create(['profile_id' => $profile->id, 'abbreviation' => $abbreviation]));
    }
}
