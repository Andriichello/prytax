<?php

namespace Tests\Feature\Services\Taxes;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PryTax\Enums\TaxType;
use PryTax\Models\Payment;
use PryTax\Models\Profile;
use PryTax\Services\Taxes\TaxLedger;
use PryTax\Services\Taxes\TaxPaymentRecorder;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;

/**
 * Class TaxLedgerTest.
 *
 * Податки as one ledger (design.md §8.5): a receipt owes its ЄП and ВЗ until a payment, which didn't fail, pays them;
 * every month of a started quarter owes its ЄСВ; the summary counts each item in the quarter of its tax, while the
 * list places it in its month, with the unpaid ones on top, then failed, sent and paid; failed payments count
 * nowhere, and the filters narrow the summary and the list alike.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Services\Taxes
 */
class TaxLedgerTest extends TestCase
{
    use ActsInProfiles;
    use RefreshDatabase;

    /**
     * Create a receipt of 1 000 USD at 41.0000 on the date, whose ЄП is 2 050.00 and ВЗ 410.00.
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
     * Record a tax payment of the profile.
     *
     * @param Profile $profile
     * @param array<string, mixed> $data
     *
     * @return void
     */
    private function pay(Profile $profile, array $data): void
    {
        $this->inProfile($profile, fn () => app(TaxPaymentRecorder::class)->record($profile, $data));
    }

    /**
     * Get the ledger of the profile's period.
     *
     * @param Profile $profile
     * @param array<int, int> $quarters
     * @param array<int, TaxType> $types
     * @param array<int, string> $statuses
     *
     * @return array<string, mixed>
     */
    private function ledger(Profile $profile, array $quarters, array $types = [], array $statuses = []): array
    {
        return $this->inProfile($profile, fn () => app(TaxLedger::class)->forPeriod($profile, 2026, $quarters, $types, $statuses));
    }

    /**
     * Get a tax's row of the summary, without its deadline.
     *
     * @param array<string, mixed> $ledger
     * @param string $type
     *
     * @return array<string, string>|null
     */
    private function row(array $ledger, string $type): ?array
    {
        $row = collect($ledger['summary']['rows'])->firstWhere('tax_type', $type);

        return $row === null ? null : array_intersect_key($row, array_flip(['accrued', 'sent', 'paid', 'failed']));
    }

    /**
     * Test that the summary counts a payment in the quarter of its tax, while the list places it in the month it was
     * paid: September's ЄСВ paid on 19.10 counts in III кв. and is listed in October.
     *
     * @return void
     */
    public function testCountsAPaymentInTheQuarterOfItsTaxAndListsItInTheMonthItWasPaid(): void
    {
        $this->travelTo('2026-10-20 10:00:00');
        $profile = $this->createProfile();
        $this->pay($profile, ['tax_type' => 'social_contribution', 'allocation' => 'month', 'period_year' => 2026, 'period_month' => 9, 'amount' => '1902.34', 'status' => 'paid', 'paid_at' => '2026-10-19']);

        $fourth = $this->ledger($profile, [4]);
        $third = $this->ledger($profile, [3]);

        $this->assertSame(['accrued' => '5707.02', 'sent' => '0.00', 'paid' => '0.00', 'failed' => '0.00'], $this->row($fourth, 'social_contribution'));
        $this->assertSame(['accrued' => '5707.02', 'sent' => '0.00', 'paid' => '1902.34', 'failed' => '0.00'], $this->row($third, 'social_contribution'));

        $october = collect($fourth['months'])->firstWhere('month', '2026-10');
        $this->assertSame(['3804.68', '1902.34'], [$october['accrued'], $october['paid']]);
        $this->assertSame(['unpaid', 'paid'], array_column($october['items'], 'status'));
        $this->assertNull(collect($third['months'])->firstWhere('month', '2026-10'));
    }

    /**
     * Test that every month of a started quarter owes its ЄСВ, the months ahead included, newest first, and a quarter,
     * which hasn't started, owes nothing yet.
     *
     * @return void
     */
    public function testListsEveryMonthOfAStartedQuarter(): void
    {
        $this->travelTo('2026-10-20 10:00:00');
        $profile = $this->createProfile();

        $months = $this->ledger($profile, [4])['months'];

        $this->assertSame(['2026-12', '2026-11', '2026-10'], array_column($months, 'month'));
        $this->assertSame(
            [['social_contribution', '1902.34', 'unpaid', 12]],
            array_map(fn (array $item) => [$item['tax_type'], $item['amount'], $item['status'], $item['period_month']], $months[0]['items'])
        );
        $this->assertSame('2027-01-19', $months[0]['items'][0]['due_at']);
        $this->assertSame([], $this->inProfile($profile, fn () => app(TaxLedger::class)->forPeriod($profile, 2027, [1]))['months']);
    }

    /**
     * Test that a month lists the unpaid items first (ЄСВ, then the receipts' taxes, newest first), then the failed,
     * sent and paid payments, and sums them without the failed one.
     *
     * @return void
     */
    public function testOrdersAMonthFromUnpaidToPaid(): void
    {
        $this->travelTo('2026-10-20 10:00:00');
        $profile = $this->createProfile();
        $earlier = $this->receipt($profile, '2026-10-05');
        $later = $this->receipt($profile, '2026-10-06');
        $this->pay($profile, ['tax_type' => 'single_tax', 'allocation' => 'payment', 'payment_id' => $earlier->id, 'status' => 'failed', 'paid_at' => '2026-10-08']);
        $this->pay($profile, ['tax_type' => 'single_tax', 'allocation' => 'payment', 'payment_id' => $earlier->id, 'status' => 'sent', 'paid_at' => '2026-10-12']);
        $this->pay($profile, ['tax_type' => 'single_tax', 'allocation' => 'payment', 'payment_id' => $later->id, 'status' => 'paid', 'paid_at' => '2026-10-15']);

        $october = collect($this->ledger($profile, [4])['months'])->firstWhere('month', '2026-10');

        $this->assertSame([
            ['social_contribution', 'unpaid', null],
            ['military_levy', 'unpaid', $later->id],
            ['military_levy', 'unpaid', $earlier->id],
            ['single_tax', 'failed', $earlier->id],
            ['single_tax', 'sent', $earlier->id],
            ['single_tax', 'paid', $later->id],
        ], array_map(fn (array $item) => [$item['tax_type'], $item['status'], $item['receipt']['id'] ?? null], $october['items']));
        $this->assertSame(['6822.34', '2050.00', '2050.00'], [$october['accrued'], $october['sent'], $october['paid']]);
    }

    /**
     * Test that the summary sums the selected statuses only, reports the failed payments apart, leaves out a tax
     * with nothing in the period, and dates each tax by the earliest deadline it still owes.
     *
     * @return void
     */
    public function testSumsTheSelectedStatusesAndReportsTheFailedOnesApart(): void
    {
        $this->travelTo('2026-10-20 10:00:00');
        $profile = $this->createProfile();
        $receipt = $this->receipt($profile, '2026-10-05');
        $this->pay($profile, ['tax_type' => 'single_tax', 'allocation' => 'payment', 'payment_id' => $receipt->id, 'status' => 'failed', 'paid_at' => '2026-10-08']);
        $this->pay($profile, ['tax_type' => 'single_tax', 'allocation' => 'payment', 'payment_id' => $receipt->id, 'status' => 'sent', 'paid_at' => '2026-10-12']);
        $this->pay($profile, ['tax_type' => 'military_levy', 'allocation' => 'payment', 'payment_id' => $receipt->id, 'status' => 'paid', 'paid_at' => '2026-10-12']);

        $all = $this->ledger($profile, [4]);
        $failedAndSent = $this->ledger($profile, [4], [], ['failed', 'sent']);

        $this->assertSame(['accrued' => '2050.00', 'sent' => '2050.00', 'paid' => '0.00', 'failed' => '2050.00'], $this->row($all, 'single_tax'));
        $this->assertSame(['accrued' => '410.00', 'sent' => '0.00', 'paid' => '410.00', 'failed' => '0.00'], $this->row($all, 'military_levy'));
        $this->assertSame(['accrued' => '8167.02', 'sent' => '2050.00', 'paid' => '410.00'], $all['summary']['total']);
        $this->assertSame(['2027-02-19', null], collect($all['summary']['rows'])->whereIn('tax_type', ['single_tax', 'military_levy'])->pluck('due_at')->all());

        $this->assertSame(['single_tax'], array_column($failedAndSent['summary']['rows'], 'tax_type'));
        $this->assertSame(['failed', 'sent'], array_column($failedAndSent['months'][0]['items'], 'status'));
    }

    /**
     * Test that the tax filter narrows the summary and the list to the chosen taxes.
     *
     * @return void
     */
    public function testNarrowsTheLedgerToTheChosenTaxes(): void
    {
        $this->travelTo('2026-10-20 10:00:00');
        $profile = $this->createProfile();
        $this->receipt($profile, '2026-10-05');

        $ledger = $this->ledger($profile, [4], [TaxType::MilitaryLevy]);

        $this->assertSame(['military_levy'], array_column($ledger['summary']['rows'], 'tax_type'));
        $this->assertSame(['2026-10'], array_column($ledger['months'], 'month'));
        $this->assertSame(['military_levy'], array_column($ledger['months'][0]['items'], 'tax_type'));
    }
}
