<?php

namespace Tests\Feature\Services\Reports;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PryTax\Models\Payment;
use PryTax\Models\Profile;
use PryTax\Services\Payments\RefundRecorder;
use PryTax\Services\Reports\TaxBreakdown;
use PryTax\Services\Taxes\TaxPaymentRecorder;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;

/**
 * Class TaxBreakdownTest.
 *
 * ЄП and ВЗ by receipt and by month (design.md §8.2): each receipt tells whether its tax is paid by its own payment,
 * covered by a month's or the quarter's, pending or unpaid; a refund is a row, which takes its hints back; and a month
 * owes the sum of its hints, with its ЄСВ month, and takes its share of the quarter's own payments.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Services\Reports
 */
class TaxBreakdownTest extends TestCase
{
    use ActsInProfiles;
    use RefreshDatabase;

    /**
     * Create a receipt of 1 000 USD at 41.0000 on the date.
     *
     * @param Profile $profile
     * @param string $date
     *
     * @return Payment
     */
    private function receipt(Profile $profile, string $date): Payment
    {
        return $this->inProfile($profile, fn () => Payment::factory()->create([
            'profile_id' => $profile->id, 'received_at' => $date, 'received_to_account_id' => $this->createAccount($profile)->id,
        ]));
    }

    /**
     * Test that the receipts' taxes are paid by their own payments, covered by a month's, or unpaid, and a refund is
     * a row with its hints taken back.
     *
     * @return void
     */
    public function testTellsHowEachReceiptsTaxIsPaid(): void
    {
        $this->travelTo('2026-12-20 10:00:00');
        $profile = $this->createProfile();
        $own = $this->receipt($profile, '2026-10-05');
        $covered = $this->receipt($profile, '2026-11-05');
        $unpaid = $this->receipt($profile, '2026-12-05');
        $this->cacheRates(['USD' => ['2026-12-10' => '42.0000']]);
        $this->inProfile($profile, function () use ($profile, $own, $unpaid) {
            $taxes = app(TaxPaymentRecorder::class);
            $taxes->record($profile, ['tax_type' => 'single_tax', 'allocation' => 'payment', 'payment_id' => $own->id, 'amount' => '2050.00', 'status' => 'paid', 'paid_at' => '2026-10-10']);
            $taxes->record($profile, ['tax_type' => 'single_tax', 'allocation' => 'month', 'period_year' => 2026, 'period_month' => 11, 'amount' => '2050.00', 'status' => 'paid', 'paid_at' => '2026-11-10']);
            app(RefundRecorder::class)->record($profile, $unpaid, ['amount' => '100.00', 'from_account_id' => $unpaid->received_to_account_id, 'refunded_at' => '2026-12-10']);
        });

        $breakdown = $this->inProfile($profile, fn () => app(TaxBreakdown::class)->byPayment($profile, 2026, 4));
        $rows = collect($breakdown['rows']);

        $this->assertSame(['paid', 'covered', 'unpaid'], $rows->where('type', 'payment')->pluck('single_tax.status')->all());
        $this->assertSame('-210.00', $rows->firstWhere('type', 'refund')['single_tax']['hint']);
        $this->assertSame('5940.00', $breakdown['check']['single_tax']['hints_sum']);
    }

    /**
     * Test that a month owes the sum of its hints, with its ЄСВ month due.
     *
     * @return void
     */
    public function testOwesTheHintsOfEachMonthWithItsEsv(): void
    {
        $profile = $this->createProfile();
        $this->receipt($profile, '2026-10-05');

        $october = $this->inProfile($profile, fn () => app(TaxBreakdown::class)->byMonth($profile, 2026)['months'][9]);

        $this->assertSame('2050.00', $october['single_tax']['owed']);
        $this->assertSame('410.00', $october['military_levy']['owed']);
        $this->assertSame('1902.34', $october['social_contribution']['owed']);
        $this->assertSame('due', $october['social_contribution']['status']);
    }

    /**
     * Test that the quarter's own payments are spread over its months, the earliest first, after the months' own
     * payments, with the rest on the quarter's last month, and paid ones before sent ones.
     *
     * @return void
     */
    public function testSpreadsTheQuartersPaymentsOverItsMonths(): void
    {
        $this->travelTo('2026-12-20 10:00:00');
        $profile = $this->createProfile();
        $this->receipt($profile, '2026-10-05');
        $this->receipt($profile, '2026-11-05');
        $this->inProfile($profile, function () use ($profile) {
            $taxes = app(TaxPaymentRecorder::class);
            $taxes->record($profile, ['tax_type' => 'single_tax', 'allocation' => 'month', 'period_year' => 2026, 'period_month' => 10, 'amount' => '1000.00', 'status' => 'paid', 'paid_at' => '2026-11-10']);
            $taxes->record($profile, ['tax_type' => 'single_tax', 'allocation' => 'quarter', 'period_year' => 2026, 'period_quarter' => 4, 'amount' => '2000.00', 'status' => 'paid', 'paid_at' => '2026-12-10']);
            $taxes->record($profile, ['tax_type' => 'single_tax', 'allocation' => 'quarter', 'period_year' => 2026, 'period_quarter' => 4, 'amount' => '1500.00', 'status' => 'sent', 'paid_at' => '2026-12-15']);
        });

        $months = $this->inProfile($profile, fn () => app(TaxBreakdown::class)->byMonth($profile, 2026)['months']);
        $taxes = fn (int $month) => array_intersect_key($months[$month - 1]['single_tax'], array_flip(['owed', 'paid', 'pending', 'from_quarter']));

        $this->assertSame(['owed' => '2050.00', 'paid' => '2050.00', 'pending' => '0.00', 'from_quarter' => '1050.00'], $taxes(10));
        $this->assertSame(['owed' => '2050.00', 'paid' => '950.00', 'pending' => '1100.00', 'from_quarter' => '2050.00'], $taxes(11));
        $this->assertSame(['owed' => '0.00', 'paid' => '0.00', 'pending' => '400.00', 'from_quarter' => '400.00'], $taxes(12));
        $this->assertSame('0.00', $months[9]['military_levy']['from_quarter']);
    }
}
