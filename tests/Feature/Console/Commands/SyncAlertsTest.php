<?php

namespace Tests\Feature\Console\Commands;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PryTax\Models\Alert;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;

/**
 * Class SyncAlertsTest.
 *
 * The daily notifications (design.md §9): every active profile's conditions are collected in its own context.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Console\Commands
 */
class SyncAlertsTest extends TestCase
{
    use ActsInProfiles;
    use RefreshDatabase;

    /**
     * Test that an overdue invoice of each active profile is reported, each in its own profile.
     *
     * @return void
     */
    public function testSyncsTheNotificationsOfEachActiveProfile(): void
    {
        Storage::fake('local');
        $this->travelTo('2026-10-20 10:00:00');
        $first = $this->createProfile();
        $second = $this->createProfile();
        $this->createFrozenInvoice($first, $this->createAccount($first), ['due_at' => '2026-10-10']);
        $this->createFrozenInvoice($second, $this->createAccount($second), ['due_at' => '2026-10-10']);

        $this->artisan('prytax:sync-alerts')->assertSuccessful();

        foreach ([$first, $second] as $profile) {
            $this->assertSame(1, Alert::query()->withoutGlobalScopes()->where('profile_id', $profile->id)->where('type', 'overdue')->count());
        }
    }
}
