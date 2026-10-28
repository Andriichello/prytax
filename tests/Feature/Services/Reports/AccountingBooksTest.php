<?php

namespace Tests\Feature\Services\Reports;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PryTax\Models\Payment;
use PryTax\Models\Profile;
use PryTax\Services\Payments\RefundRecorder;
use PryTax\Services\Reports\AccountingBooks;
use PryTax\Services\Reports\IncomeBook;
use PryTax\Services\Taxes\TaxLedger;
use PryTax\Services\Taxes\TaxPaymentRecorder;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;

/**
 * Class AccountingBooksTest.
 *
 * Книги обліку (design.md §16.7): the income book lists the started quarters and months newest first, with the
 * running totals carried from 1 January, empty months as «без доходу» or «ще немає», and refunds on their own date;
 * the taxes match the ledger, cumulate from I кв., count sent amounts apart from paid ones and ignore failed ones.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Services\Reports
 */
class AccountingBooksTest extends TestCase
{
    use ActsInProfiles;
    use RefreshDatabase;

    /**
     * Create a receipt of 1 000 USD at 41.0000 on the date: 41 000.00 ₴ of income, whose ЄП is 2 050.00 and ВЗ 410.00.
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
     * Get a book of the profile's year.
     *
     * @param Profile $profile
     * @param string $book
     * @param int $year
     *
     * @return array<string, mixed>
     */
    private function book(Profile $profile, string $book, int $year = 2026): array
    {
        return $this->inProfile($profile, fn () => app(AccountingBooks::class)->{$book}($profile, $year));
    }

    /**
     * Test that the income book lists the started quarters and months newest first, the months without income as
     * such, and carries the running total over them.
     *
     * @return void
     */
    public function testListsTheStartedMonthsNewestFirstWithTheRunningTotals(): void
    {
        $this->travelTo('2026-10-20 10:00:00');
        $profile = $this->createProfile();
        $this->receipt($profile, '2026-01-15');
        $this->receipt($profile, '2026-03-10');
        $this->receipt($profile, '2026-10-05');

        $book = $this->book($profile, 'income');
        $quarters = collect($book['quarters'])->keyBy('quarter');

        $this->assertSame('123000.00', $book['ytd']);
        $this->assertSame([4, 3, 2, 1], array_column($book['quarters'], 'quarter'));
        $this->assertSame([10], array_column($quarters[4]['months'], 'month'));
        $this->assertTrue($quarters[4]['current']);
        $this->assertSame(['41000.00', '123000.00'], [$quarters[4]['total'], $quarters[4]['ytd_end']]);
        $this->assertSame([9, 8, 7], array_column($quarters[3]['months'], 'month'));
        $this->assertSame(['no_income', 'no_income', 'no_income'], array_column($quarters[3]['months'], 'state'));
        $this->assertSame('82000.00', $quarters[3]['ytd_end']);
        $january = collect($quarters[1]['months'])->firstWhere('month', 1);
        $this->assertSame(['ok', 1], [$january['state'], $january['count']]);
        $this->assertSame([['2026-01-15', '41000.00', '41000.00']], array_map(fn (array $receipt) => [$receipt['credited_on'], $receipt['amount_uah'], $receipt['ytd_after']], $january['receipts']));
        $this->assertSame('1.2', $book['limit']['percent']);
    }

    /**
     * Test that a quarter shows from its first day at 0.00, its current month without income as «ще немає», and a
     * year without receipts says so.
     *
     * @return void
     */
    public function testShowsAQuarterFromItsFirstDay(): void
    {
        $this->travelTo('2026-10-01 10:00:00');
        $profile = $this->createProfile();
        $this->receipt($profile, '2026-09-10');

        $fourth = $this->book($profile, 'income')['quarters'][0];

        $this->assertSame([4, '0.00', '41000.00'], [$fourth['quarter'], $fourth['total'], $fourth['ytd_end']]);
        $this->assertSame([['month' => 10, 'state' => 'not_yet']], array_map(fn (array $month) => array_intersect_key($month, ['month' => 1, 'state' => 1]), $fourth['months']));
        $this->assertFalse($this->book($profile, 'income', 2025)['has_receipts']);
    }

    /**
     * Test that a refund reduces the income of the day it was made (at that day's NBU rate), not the receipt's, and the
     * year's income is Облік's (the income book of the accounting).
     *
     * @return void
     */
    public function testReducesTheIncomeOnTheDayOfARefund(): void
    {
        Storage::fake('local');
        $this->travelTo('2026-10-20 10:00:00');
        $profile = $this->createProfile();
        $wise = $this->createAccount($profile);
        $this->cacheRates(['USD' => ['2026-09-10' => '41.0000', '2026-10-10' => '42.0000']]);
        $invoice = $this->createFrozenInvoice($profile, $wise);
        $receipt = $this->recordReceipt($profile, $invoice, $wise, ['amount' => '3000.00', 'received_at' => '2026-09-10']);
        $this->inProfile($profile, fn () => app(RefundRecorder::class)->record($profile, $receipt, [
            'amount' => '100.00', 'from_account_id' => $wise->id, 'refunded_at' => '2026-10-10',
        ]));

        $book = $this->book($profile, 'income');
        $october = $book['quarters'][0]['months'][0];
        $september = $book['quarters'][1]['months'][0];

        $this->assertSame(['123000.00', '123000.00'], [$september['total'], $september['ytd_end']]);
        $this->assertSame(['refund', '-4200.00', '118800.00'], [$october['receipts'][0]['kind'], $october['receipts'][0]['amount_uah'], $october['receipts'][0]['ytd_after']]);
        $this->assertSame('-4200.00', $october['total']);
        $this->assertSame($this->inProfile($profile, fn () => app(IncomeBook::class)->forYear($profile, 2026))['total'], $book['ytd']);
    }

    /**
     * Test that the taxes match the ledger's year, cumulate from I кв. (a quarter is the cumulative less the previous
     * period), count ЄСВ for every month of a started quarter, show sent amounts apart from paid ones and ignore failed
     * payments.
     *
     * @return void
     */
    public function testCumulatesTheLedgersTaxes(): void
    {
        $this->travelTo('2026-10-20 10:00:00');
        $profile = $this->createProfile();
        $first = $this->receipt($profile, '2026-02-10');
        $fourth = $this->receipt($profile, '2026-10-05');
        $pay = fn (array $data) => $this->inProfile($profile, fn () => app(TaxPaymentRecorder::class)->record($profile, $data));
        $pay(['tax_type' => 'social_contribution', 'allocation' => 'month', 'period_year' => 2026, 'period_month' => 9, 'amount' => '1902.34', 'status' => 'paid', 'paid_at' => '2026-10-19']);
        $pay(['tax_type' => 'single_tax', 'allocation' => 'payment', 'payment_id' => $fourth->id, 'status' => 'sent', 'paid_at' => '2026-10-12']);
        $pay(['tax_type' => 'military_levy', 'allocation' => 'payment', 'payment_id' => $first->id, 'status' => 'failed', 'paid_at' => '2026-03-12']);

        $taxes = $this->book($profile, 'taxes');
        $ledger = $this->inProfile($profile, fn () => app(TaxLedger::class)->forPeriod($profile, 2026));
        $rows = fn (int $quarter) => collect(collect($taxes['cumulative'])->firstWhere('to_quarter', $quarter)['rows'])->keyBy('tax_type');

        $this->assertSame([$ledger['summary']['total']['accrued'], $ledger['summary']['total']['paid']], [$taxes['totals']['accrued'], $taxes['totals']['paid']]);
        $this->assertSame([4, 3, 2, 1], array_column($taxes['cumulative'], 'to_quarter'));
        $this->assertSame(['5707.02', '22828.08'], [$rows(4)['social_contribution']['quarter'], $rows(4)['social_contribution']['cumulative']]);
        $this->assertSame(['2050.00', '4100.00', '2050.00'], [$rows(4)['single_tax']['quarter'], $rows(4)['single_tax']['cumulative'], $rows(3)['single_tax']['cumulative']]);
        $this->assertSame(['0.00', '2050.00'], [$rows(4)['single_tax']['paid'], $rows(4)['single_tax']['sent']]);
        $this->assertSame(['410.00', '0.00'], [$rows(1)['military_levy']['cumulative'], $rows(1)['military_levy']['paid']]);

        $byTax = collect($taxes['by_tax'])->keyBy('tax_type');
        $esv = array_column($byTax['social_contribution']['segments'], 'state', 'period');
        $this->assertSame(['paid', 'unpaid', 'unpaid'], [$esv[9], $esv[10], $esv[12]]);
        $this->assertSame([1 => 'unpaid', 2 => 'none', 3 => 'none', 4 => 'sent'], array_column($byTax['single_tax']['segments'], 'state', 'period'));
    }
}
