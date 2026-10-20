<?php

namespace Tests\Feature\Services\Reports;

use Core\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PryTax\Models\Payment;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;

/**
 * Class PracticeOverviewTest.
 *
 * Огляд практики (design.md §16): a quarter shows every profile, which was active in it, a closed one included, the
 * rest of the inactive ones on request, and each tax with what is still outstanding.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Services\Reports
 */
class PracticeOverviewTest extends TestCase
{
    use ActsInProfiles;
    use RefreshDatabase;

    /**
     * Test that a profile closed in a quarter is shown in it and hidden later, unless the inactive ones are asked for.
     *
     * @return void
     */
    public function testShowsAProfileInTheQuartersItWasActiveIn(): void
    {
        $this->travelTo('2026-10-20 10:00:00');
        $organization = Organization::factory()->create();
        $active = $this->createProfile($organization, ['full_name' => 'Коваленко Олена Петрівна']);
        $closed = $this->createProfile($organization, ['full_name' => 'Савчук Тарас Олегович', 'is_active' => false, 'closed_at' => '2026-03-31']);
        $this->actAs($active, 'viewer');

        $first = $this->getJson('/api/overview/2026/1');
        $fourth = $this->getJson('/api/overview/2026/4');
        $all = $this->getJson('/api/overview/2026/4?with_inactive=1');

        $first->assertOk()->assertJsonPath('data.profiles.*.profile.id', [$active->id, $closed->id]);
        $fourth->assertOk()->assertJsonPath('data.profiles.*.profile.id', [$active->id])->assertJsonPath('data.inactive_count', 1);
        $all->assertOk()->assertJsonPath('data.profiles.*.profile.id', [$active->id, $closed->id]);
    }

    /**
     * Test that each tax of the quarter tells what is still outstanding of it.
     *
     * @return void
     */
    public function testTellsWhatIsOutstandingOfEachTax(): void
    {
        $this->travelTo('2026-10-20 10:00:00');
        $profile = $this->createProfile();
        $this->actAs($profile, 'viewer');
        $this->inProfile($profile, fn () => Payment::factory()->create([
            'profile_id' => $profile->id, 'received_to_account_id' => $this->createAccount($profile)->id, 'received_at' => '2026-07-05',
        ]));

        $response = $this->getJson('/api/overview/2026/3');

        $response->assertOk()
            ->assertJsonPath('data.profiles.0.taxes.single_tax.outstanding', '2050.00')
            ->assertJsonPath('data.profiles.0.taxes.social_contribution.outstanding', '5707.02')
            ->assertJsonPath('data.profiles.0.taxes.social_contribution.balance', '-17121.06');
    }
}
