<?php

namespace Tests\Feature\Http\Controllers;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PryTax\Models\Payment;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;

/**
 * Class TaxReportControllerTest.
 *
 * The tax ledger's endpoint (design.md §8.5): it narrows the ledger to the quarters, taxes and statuses of the query,
 * and refuses an unknown one with 422.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Http\Controllers
 */
class TaxReportControllerTest extends TestCase
{
    use ActsInProfiles;
    use RefreshDatabase;

    /**
     * Test that the ledger is narrowed to the quarters, taxes and statuses of the query, given comma-separated.
     *
     * @return void
     */
    public function testNarrowsTheLedgerToTheQuery(): void
    {
        $this->travelTo('2026-10-20 10:00:00');
        $profile = $this->createProfile();
        $this->inProfile($profile, fn () => Payment::factory()->create(['profile_id' => $profile->id, 'received_at' => '2026-10-05']));
        $this->actAs($profile, 'viewer');

        $this->getJson($this->profilePath($profile, 'taxes/2026/ledger?quarter=4&tax_type=military_levy&status=unpaid,sent'))
            ->assertOk()
            ->assertJsonPath('data.quarters', [4])
            ->assertJsonPath('data.summary.rows.0.tax_type', 'military_levy')
            ->assertJsonPath('data.summary.rows.0.accrued', '410.00')
            ->assertJsonCount(1, 'data.summary.rows')
            ->assertJsonPath('data.months.0.month', '2026-10')
            ->assertJsonCount(1, 'data.months');
    }

    /**
     * Test that an unknown status or quarter is refused with 422.
     *
     * @return void
     */
    public function testReturns422ForAnUnknownStatusOrQuarter(): void
    {
        $profile = $this->createProfile();
        $this->actAs($profile);

        $this->getJson($this->profilePath($profile, 'taxes/2026/ledger?status[]=due&quarter[]=5'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status.0', 'quarter.0']);
    }
}
