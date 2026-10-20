<?php

namespace Tests\Feature\Services\Reports;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PryTax\Services\Reports\EsvTable;
use PryTax\Services\Taxes\TaxPaymentRecorder;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;

/**
 * Class EsvTableTest.
 *
 * The year of ЄСВ agrees with Податки: paying more than a quarter's minimum leaves a credit, which carries to the next
 * quarters, and its balances are the running ones.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Services\Reports
 */
class EsvTableTest extends TestCase
{
    use ActsInProfiles;
    use RefreshDatabase;

    /**
     * Test that a quarter paid above its minimum carries the credit to the next quarter's balance.
     *
     * @return void
     */
    public function testCarriesTheCreditOfAQuarterPaidAboveItsMinimum(): void
    {
        $this->travelTo('2026-10-20 10:00:00');
        $profile = $this->createProfile();
        $this->inProfile($profile, fn () => app(TaxPaymentRecorder::class)->record($profile, [
            'tax_type' => 'social_contribution', 'allocation' => 'quarter', 'period_year' => 2026, 'period_quarter' => 1,
            'amount' => '10000.00', 'status' => 'paid', 'paid_at' => '2026-04-15',
        ]));

        $table = $this->inProfile($profile, fn () => app(EsvTable::class)->forYear($profile, 2026));

        $this->assertSame('4292.98', $table['quarters'][0]['balance']);
        $this->assertTrue($table['quarters'][0]['is_paid']);
        $this->assertSame('-1414.04', $table['quarters'][1]['balance']);
        $this->assertFalse($table['quarters'][1]['is_paid']);
        $this->assertSame('-12828.08', $table['balance']);
        $this->assertSame('12828.08', $table['remaining']);
    }
}
