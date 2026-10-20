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
            ->assertJsonPath('data.locked_at', '2026-11-05T10:00:00.000000Z')
            ->assertJsonPath('data.declaration_submitted_at', '2026-11-05T10:00:00.000000Z');

        $this->patchJson($this->profilePath($profile, "tax-periods/{$period->id}"), ['declaration_status' => 'rejected'])
            ->assertOk()
            ->assertJsonPath('data.locked_at', null);
    }

    /**
     * Test that a declaration's status changes along its steps only: an accepted one can't go back to none (409),
     * `corrected` isn't set by hand (422), and a quarter, which hasn't ended, isn't submitted (422).
     *
     * @return void
     */
    public function testChangesTheDeclarationAlongItsStepsOnly(): void
    {
        $this->travelTo('2026-11-05 12:00:00');
        $profile = $this->createProfile();
        $this->actAs($profile, 'accountant');
        $accepted = $this->declareQuarter($profile, 2026, 3);
        $current = $this->inProfile($profile, fn () => app(DeadlineScheduler::class)->ensure($profile, 2026, 4));

        $this->patchJson($this->profilePath($profile, "tax-periods/{$accepted->id}"), ['declaration_status' => 'none'])
            ->assertConflict()
            ->assertJsonPath('message', "The declaration can't change from accepted to none.");
        $this->patchJson($this->profilePath($profile, "tax-periods/{$accepted->id}"), ['declaration_status' => 'corrected'])
            ->assertUnprocessable();
        $this->patchJson($this->profilePath($profile, "tax-periods/{$current->id}"), ['declaration_status' => 'submitted'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['declaration_status' => 'A declaration is submitted after its quarter ends.']);

        $this->assertNotNull($accepted->fresh()->locked_at);
        $this->assertSame('none', $current->fresh()->declaration_status->value);
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
