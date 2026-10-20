<?php

namespace Tests\Feature\Services\Reports;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PryTax\Services\Reports\DeclarationFigures;
use PryTax\Services\Taxes\TaxPaymentRecorder;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;

/**
 * Class DeclarationFiguresTest.
 *
 * The declaration's figures of a quarter: its ЄСВ with its payment, for every quarter, and every month of the year for
 * the annual one, with the income cap.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Services\Reports
 */
class DeclarationFiguresTest extends TestCase
{
    use ActsInProfiles;
    use RefreshDatabase;

    /**
     * Test that a quarter's declaration tells its ЄСВ and when it was paid, while the months of the year are the
     * annual declaration's.
     *
     * @return void
     */
    public function testGivesTheEsvOfTheQuarterWithItsPayment(): void
    {
        $this->travelTo('2026-10-20 10:00:00');
        $profile = $this->createProfile();
        $this->inProfile($profile, fn () => app(TaxPaymentRecorder::class)->record($profile, [
            'tax_type' => 'social_contribution', 'allocation' => 'quarter', 'period_year' => 2026, 'period_quarter' => 3,
            'amount' => '5707.02', 'status' => 'paid', 'paid_at' => '2026-10-15',
        ]));

        [$third, $fourth] = $this->inProfile($profile, fn () => [
            app(DeclarationFigures::class)->forQuarter($profile, 2026, 3),
            app(DeclarationFigures::class)->forQuarter($profile, 2026, 4),
        ]);

        $this->assertSame('5707.02', $third['esv']['owed']);
        $this->assertSame('2026-10-15', $third['esv_quarter']['paid_at']);
        $this->assertTrue($third['esv_quarter']['is_paid']);
        $this->assertNull($third['esv_months']);
        $this->assertCount(12, $fourth['esv_months']);
        $this->assertSame('10091049.00', $third['income_cap']['income_cap']);
    }
}
