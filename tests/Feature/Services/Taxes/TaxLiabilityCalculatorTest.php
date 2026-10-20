<?php

namespace Tests\Feature\Services\Taxes;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PryTax\Models\Payment;
use PryTax\Models\Profile;
use PryTax\Models\Refund;
use PryTax\Models\TaxRule;
use PryTax\Services\Taxes\TaxLiabilityCalculator;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;

/**
 * Class TaxLiabilityCalculatorTest.
 *
 * Liabilities of a quarter (design.md §8.1): the owed ЄП and ВЗ are the year-to-date delta, and the standalone
 * quarter figure and the sum of the per-receipt hints are cross-checks with their kopeck deltas.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Services\Taxes
 */
class TaxLiabilityCalculatorTest extends TestCase
{
    use ActsInProfiles;
    use RefreshDatabase;

    /**
     * Create a receipt of the given UAH income on the date, with its hints as the snapshot computes them.
     *
     * @param Profile $profile
     * @param string $date
     * @param string $uah
     * @param string $singleTax
     * @param string $militaryLevy
     *
     * @return Payment
     */
    private function income(Profile $profile, string $date, string $uah, string $singleTax, string $militaryLevy): Payment
    {
        return $this->inProfile($profile, fn () => Payment::factory()->create([
            'profile_id' => $profile->id,
            'currency' => 'UAH',
            'amount' => $uah,
            'nbu_rate' => '1.0000',
            'uah_amount' => $uah,
            'received_at' => $date,
            'single_tax_uah' => $singleTax,
            'military_levy_uah' => $militaryLevy,
            'tax_rule_id' => TaxRule::query()->effectiveOn($date)->value('id'),
        ]));
    }

    /**
     * Test that a quarter owes its year-to-date liability less the previous quarter's, and shows the standalone and
     * per-receipt figures with their kopeck deltas (the mock's III кв. 2026: 503 118.73 ₴ of 1 487 175.04 ₴).
     *
     * @return void
     */
    public function testOwesTheYearToDateDeltaAndShowsTheCrossChecks(): void
    {
        $profile = $this->createProfile();
        $this->income($profile, '2026-05-06', '984056.31', '49202.82', '9840.56');
        $this->income($profile, '2026-07-07', '162982.87', '8149.14', '1629.83');
        $this->income($profile, '2026-08-06', '158908.11', '7945.41', '1589.08');
        $this->income($profile, '2026-09-04', '164893.74', '8244.69', '1648.94');
        $this->income($profile, '2026-09-22', '16334.01', '816.70', '163.34');

        $liability = $this->inProfile($profile, fn () => app(TaxLiabilityCalculator::class)->forQuarter($profile, 2026, 3));

        $this->assertSame('503118.73', $liability['quarter_income']);
        $this->assertSame('1487175.04', $liability['ytd_income']);
        $this->assertSame(4, $liability['payments_count']);
        $this->assertSame([
            'rate' => '0.0500',
            'ytd' => '74358.75',
            'previous_ytd' => '49202.82',
            'owed' => '25155.93',
            'standalone' => '25155.94',
            'standalone_delta' => '-0.01',
            'hints_sum' => '25155.94',
            'hints_delta' => '-0.01',
        ], $liability['single_tax']);
        $this->assertSame('5031.19', $liability['military_levy']['owed']);
        $this->assertSame('14.7', $liability['cap']['percent']);
    }

    /**
     * Test that a refund reduces the income of the quarter of its date, not of its receipt.
     *
     * @return void
     */
    public function testReducesTheIncomeOfTheRefundsQuarter(): void
    {
        $profile = $this->createProfile();
        $payment = $this->income($profile, '2026-03-10', '10000.00', '500.00', '100.00');
        $this->inProfile($profile, fn () => (new Refund)->forceFill([
            'profile_id' => $profile->id,
            'payment_id' => $payment->id,
            'from_account_id' => $payment->received_to_account_id,
            'amount' => '1000.00',
            'currency' => 'UAH',
            'refunded_at' => '2026-04-15',
            'nbu_rate' => '1.0000',
            'nbu_rate_date' => '2026-04-15',
            'uah_amount' => '1000.00',
            'tax_rule_id' => $payment->tax_rule_id,
        ])->save());

        [$first, $second] = $this->inProfile($profile, fn () => [
            app(TaxLiabilityCalculator::class)->forQuarter($profile, 2026, 1),
            app(TaxLiabilityCalculator::class)->forQuarter($profile, 2026, 2),
        ]);

        $this->assertSame('10000.00', $first['quarter_income']);
        $this->assertSame('-1000.00', $second['quarter_income']);
        $this->assertSame('9000.00', $second['ytd_income']);
        $this->assertSame('-50.00', $second['single_tax']['owed']);
        $this->assertSame('-50.00', $second['single_tax']['hints_sum']);
    }

    /**
     * Test that income under a rule, whose ВЗ rate differs, is a segment of its own in the year-to-date figure.
     *
     * @return void
     */
    public function testSplitsTheYearIntoSegmentsOfTaxRules(): void
    {
        $profile = $this->createProfile();
        $rule = TaxRule::query()->create([
            'effective_from' => '2026-07-01', 'single_tax_rate' => '0.0500', 'military_levy_rate' => '0.0000',
            'excess_rate' => '0.1500', 'min_wage' => '8647.00', 'esv_monthly' => '1902.34', 'esv_mandatory' => true,
            'esv_due_day' => 19, 'income_cap_uah' => '10091049.00',
        ]);
        $this->income($profile, '2026-05-05', '100000.00', '5000.00', '1000.00');
        $this->income($profile, '2026-08-05', '100000.00', '5000.00', '0.00')->forceFill(['tax_rule_id' => $rule->id])->saveQuietly();

        $liability = $this->inProfile($profile, fn () => app(TaxLiabilityCalculator::class)->forQuarter($profile, 2026, 3));

        $this->assertSame('1000.00', $liability['military_levy']['ytd']);
        $this->assertSame('0.00', $liability['military_levy']['owed']);
        $this->assertSame('10000.00', $liability['single_tax']['ytd']);
    }

    /**
     * Test that year-to-date income above the cap is taxed at 15 % instead of 5 %, in the year-to-date figure and in
     * what the quarter owes (п. 293.4), while ВЗ stays 1 % of all income.
     *
     * @return void
     */
    public function testTaxesTheIncomeAboveTheCapAtTheExcessRate(): void
    {
        $profile = $this->createProfile();
        $this->income($profile, '2026-02-10', '10000000.00', '500000.00', '100000.00');
        $this->income($profile, '2026-05-10', '191049.00', '9552.45', '1910.49');

        $liability = $this->inProfile($profile, fn () => app(TaxLiabilityCalculator::class)->forQuarter($profile, 2026, 2));

        $this->assertSame('100000.00', $liability['cap']['excess']);
        $this->assertSame('519552.45', $liability['single_tax']['ytd']);
        $this->assertSame('500000.00', $liability['single_tax']['previous_ytd']);
        $this->assertSame('19552.45', $liability['single_tax']['owed']);
        $this->assertSame('19552.45', $liability['single_tax']['standalone']);
        $this->assertSame('1910.49', $liability['military_levy']['owed']);
    }
}
