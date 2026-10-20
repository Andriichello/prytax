<?php

namespace Tests\Feature\Http\Controllers;

use Core\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PryTax\Models\Profile;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;

/**
 * Class ProfileControllerTest.
 *
 * Profiles (ФОП) of an organization: owners create them, with their tax id encrypted and unique within the
 * organization, and their tax periods; accountants can't; the list is the organization's.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Http\Controllers
 */
class ProfileControllerTest extends TestCase
{
    use ActsInProfiles;
    use RefreshDatabase;

    /**
     * Test that an owner creates a profile, whose tax id is stored encrypted, with its tax periods and ЄСВ months.
     *
     * @return void
     */
    public function testCreatesAProfileWithItsTaxPeriods(): void
    {
        $this->travelTo('2026-10-20 12:00:00');
        $organization = Organization::factory()->create();
        Sanctum::actingAs($this->userOf($organization));

        $response = $this->postJson('/api/profiles', [
            'full_name' => 'Коваленко Олена Петрівна', 'tax_id' => '32147 04821', 'registered_at' => '2023-04-01',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.tax_id_masked', '••••4821')
            ->assertJsonPath('data.short_name', 'Коваленко О. П.')
            ->assertJsonMissingPath('data.tax_id_hash');
        $profile = Profile::query()->withoutGlobalScopes()->findOrFail($response->json('data.id'));
        $this->assertSame('3214704821', $profile->tax_id);
        $this->assertNotSame('3214704821', $profile->getRawOriginal('tax_id'));
        $this->assertDatabaseHas('tax_periods', ['profile_id' => $profile->id, 'year' => 2027, 'quarter' => 1]);
        $this->assertDatabaseHas('esv_obligations', ['profile_id' => $profile->id, 'year' => 2026, 'month' => 12]);
    }

    /**
     * Test that a tax id of another profile of the organization, and a malformed one, are refused.
     *
     * @return void
     */
    public function testReturns422ForATakenOrMalformedTaxId(): void
    {
        $profile = $this->createProfile(null, ['tax_id' => '3214704821']);
        $this->actAs($profile);

        $this->postJson('/api/profiles', ['full_name' => 'Двійник', 'tax_id' => '3214704821'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tax_id' => 'Another ФОП of the organization has this tax id.']);
        $this->postJson('/api/profiles', ['full_name' => 'Помилка', 'tax_id' => '12345'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tax_id' => 'РНОКПП is 10 digits.']);
    }

    /**
     * Test that the same tax id may exist in another organization.
     *
     * @return void
     */
    public function testAllowsTheSameTaxIdInAnotherOrganization(): void
    {
        $this->createProfile(null, ['tax_id' => '3214704821']);
        Sanctum::actingAs($this->userOf(Organization::factory()->create()));

        $this->postJson('/api/profiles', ['full_name' => 'Коваленко Олена Петрівна', 'tax_id' => '3214704821'])->assertCreated();
    }

    /**
     * Test that an accountant can't create a profile, but lists the organization's ones only.
     *
     * @return void
     */
    public function testLetsAccountantsListButNotCreateProfiles(): void
    {
        $profile = $this->createProfile();
        $this->createProfile();
        $this->actAs($profile, 'accountant');

        $this->postJson('/api/profiles', ['full_name' => 'Новий ФОП', 'tax_id' => '1234567890'])->assertForbidden();
        $this->getJson('/api/profiles')->assertOk()->assertJsonPath('data.*.id', [$profile->id]);
    }

    /**
     * Test that viewers see the masked tax id only, while accountants see the full one.
     *
     * @return void
     */
    public function testShowsTheFullTaxIdOnlyToUsersWhoConfigureTheProfile(): void
    {
        $profile = $this->createProfile(null, ['tax_id' => '3214704821']);

        $this->actAs($profile, 'viewer');
        $viewed = $this->getJson("/api/profiles/{$profile->id}");
        $this->actAs($profile, 'accountant');
        $configured = $this->getJson("/api/profiles/{$profile->id}");

        $viewed->assertOk()->assertJsonMissingPath('data.tax_id')->assertJsonPath('data.tax_id_masked', '••••4821');
        $configured->assertOk()->assertJsonPath('data.tax_id', '3214704821');
    }

    /**
     * Test that an accountant can't change the identity of a profile (403), which owners keep.
     *
     * @return void
     */
    public function testReturns403WhenAnAccountantChangesTheIdentityOfAProfile(): void
    {
        $profile = $this->createProfile(null, ['tax_id' => '3214704821']);
        $this->actAs($profile, 'accountant');

        $this->patchJson("/api/profiles/{$profile->id}", ['tax_id' => '1234567890', 'is_active' => false])->assertForbidden();

        $this->assertSame('3214704821', $profile->fresh()->tax_id);
        $this->assertTrue($profile->fresh()->is_active);
    }
}
