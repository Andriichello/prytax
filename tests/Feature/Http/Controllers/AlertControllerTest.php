<?php

namespace Tests\Feature\Http\Controllers;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PryTax\Models\Alert;
use PryTax\Models\Profile;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;

/**
 * Class AlertControllerTest.
 *
 * Notifications are read only by an explicit tap (design.md §9): listing never marks them, and a read is the
 * user's own.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Http\Controllers
 */
class AlertControllerTest extends TestCase
{
    use ActsInProfiles;
    use RefreshDatabase;

    /**
     * Create a notification of the profile.
     *
     * @param Profile $profile
     * @param string $category
     * @param string $key
     *
     * @return Alert
     */
    private function alert(Profile $profile, string $category, string $key): Alert
    {
        return $this->inProfile($profile, fn () => Alert::query()->create([
            'type' => 'overdue', 'category' => $category, 'severity' => 'info', 'dedupe_key' => $key, 'title' => 'Інвойс прострочено',
        ]));
    }

    /**
     * Test that the list, which a viewer loads, marks nothing as read.
     *
     * @return void
     */
    public function testListingNeverMarksNotificationsRead(): void
    {
        $profile = $this->createProfile();
        $this->actAs($profile, 'viewer');
        $this->alert($profile, 'invoices', 'overdue:1');

        $this->getJson($this->profilePath($profile, 'alerts'))
            ->assertOk()
            ->assertJsonPath('data.0.is_read', false)
            ->assertJsonPath('data.0.read_at', null);
        $this->assertDatabaseCount('alert_reads', 0);
    }

    /**
     * Test that a tap marks the notification read for the user who tapped it, once, and not for the others.
     *
     * @return void
     */
    public function testMarksANotificationReadForTheUserWhoTappedIt(): void
    {
        $profile = $this->createProfile();
        $alert = $this->alert($profile, 'invoices', 'overdue:1');
        $reader = $this->actAs($profile, 'viewer');

        $this->postJson($this->profilePath($profile, "alerts/{$alert->id}/read"))->assertOk()->assertJsonPath('data.is_read', true);
        $this->postJson($this->profilePath($profile, "alerts/{$alert->id}/read"))->assertOk();

        $this->assertDatabaseCount('alert_reads', 1);
        $this->assertDatabaseHas('alert_reads', ['alert_id' => $alert->id, 'user_id' => $reader->id]);
        Sanctum::actingAs($this->userOf($profile->organization, 'accountant'));
        $this->getJson($this->profilePath($profile, 'alerts'))->assertOk()->assertJsonPath('data.0.is_read', false);
    }

    /**
     * Test that the counts of the chips are per category, with the user's unread ones, of unresolved notifications.
     *
     * @return void
     */
    public function testCountsTheUnresolvedNotificationsByCategory(): void
    {
        $profile = $this->createProfile();
        $read = $this->alert($profile, 'invoices', 'overdue:1');
        $this->alert($profile, 'invoices', 'overdue:2');
        $this->alert($profile, 'funds', 'limit:1:80');
        $this->alert($profile, 'funds', 'limit:2:80')->forceFill(['resolved_at' => now()])->save();
        $this->actAs($profile, 'viewer');
        $this->postJson($this->profilePath($profile, "alerts/{$read->id}/read"))->assertOk();

        $this->getJson($this->profilePath($profile, 'alerts/counts'))
            ->assertOk()
            ->assertJsonPath('data.all', 3)
            ->assertJsonPath('data.unread', 2)
            ->assertJsonPath('data.categories.invoices', ['all' => 2, 'unread' => 1])
            ->assertJsonPath('data.categories.funds', ['all' => 1, 'unread' => 1])
            ->assertJsonPath('data.categories.deadlines', ['all' => 0, 'unread' => 0]);
    }
}
