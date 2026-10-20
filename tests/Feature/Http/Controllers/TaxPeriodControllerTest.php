<?php

namespace Tests\Feature\Http\Controllers;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PryTax\Services\Taxes\DeadlineScheduler;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;

/**
 * Class TaxPeriodControllerTest.
 *
 * A submitted or accepted declaration locks its quarter; one returned to filled unlocks it (design.md §8.3).
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Http\Controllers
 */
class TaxPeriodControllerTest extends TestCase
{
    use ActsInProfiles;
    use RefreshDatabase;

    /**
     * Test that marking the declaration submitted locks the quarter, and filled unlocks it.
     *
     * @return void
     */
    public function testLocksTheQuarterOfASubmittedDeclaration(): void
    {
        $this->travelTo('2026-11-05 12:00:00');
        $profile = $this->createProfile();
        $this->actAs($profile, 'accountant');
        $period = $this->inProfile($profile, fn () => app(DeadlineScheduler::class)->ensure($profile, 2026, 3));

        $this->patchJson($this->profilePath($profile, "tax-periods/{$period->id}"), ['declaration_status' => 'submitted'])
            ->assertOk()
            ->assertJsonPath('data.declaration_status', 'submitted')
            ->assertJsonPath('data.locked_at', '2026-11-05T12:00:00.000000Z')
            ->assertJsonPath('data.declaration_submitted_at', '2026-11-05T12:00:00.000000Z');

        $this->patchJson($this->profilePath($profile, "tax-periods/{$period->id}"), ['declaration_status' => 'filled'])
            ->assertOk()
            ->assertJsonPath('data.locked_at', null);
    }

    /**
     * Test that a viewer can't change a declaration's status.
     *
     * @return void
     */
    public function testReturns403WhenAViewerChangesTheDeclaration(): void
    {
        $profile = $this->createProfile();
        $this->actAs($profile, 'viewer');
        $period = $this->inProfile($profile, fn () => app(DeadlineScheduler::class)->ensure($profile, 2026, 3));

        $this->patchJson($this->profilePath($profile, "tax-periods/{$period->id}"), ['declaration_status' => 'submitted'])->assertForbidden();
        $this->assertDatabaseHas('tax_periods', ['id' => $period->id, 'declaration_status' => 'none', 'locked_at' => null]);
    }
}
