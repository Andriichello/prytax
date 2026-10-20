<?php

namespace Tests\Feature\Services\Reports;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PryTax\Models\Payment;
use PryTax\Services\Reports\FeeStatistics;
use PryTax\Services\Transfers\TransferRecorder;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;

/**
 * Class FeeStatisticsTest.
 *
 * What the money paths cost (accounting.md): fees and spread in UAH by account and by client, and each quarter's net
 * income after fees, ЄП, ВЗ and ЄСВ.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Services\Reports
 */
class FeeStatisticsTest extends TestCase
{
    use ActsInProfiles;
    use RefreshDatabase;

    /**
     * Test that a receipt's fee counts for its account, a transfer's for the account it left, both for the client,
     * and the quarter nets its income less fees and taxes.
     *
     * @return void
     */
    public function testCountsTheFeesByAccountClientAndQuarter(): void
    {
        $profile = $this->createProfile();
        $payoneer = $this->createAccount($profile, ['name' => 'Payoneer USD']);
        $wise = $this->createAccount($profile);
        $payment = $this->inProfile($profile, fn () => Payment::factory()->create([
            'profile_id' => $profile->id, 'received_to_account_id' => $payoneer->id, 'received_amount' => '990.00',
        ]));
        $this->cacheRates(['USD' => ['2026-10-10' => '41.0000']]);
        $this->inProfile($profile, fn () => app(TransferRecorder::class)->record($profile, [
            'from_account_id' => $payoneer->id, 'to_account_id' => $wise->id, 'amount_sent' => '990.00', 'amount_received' => '975.00',
            'received_at' => '2026-10-10', 'allocations' => [['payment_id' => $payment->id, 'amount' => '990.00']],
        ]));

        $stats = $this->inProfile($profile, fn () => app(FeeStatistics::class)->forYear($profile, 2026));

        $this->assertSame(['Payoneer USD', '1025.00'], [$stats['by_account'][0]['name'], $stats['by_account'][0]['fees']]);
        $this->assertSame('1025.00', $stats['by_client'][0]['fees']);
        $this->assertSame('1025.00', $stats['quarters'][3]['fees']);
        $this->assertSame('31807.98', $stats['quarters'][3]['net_income']);
    }
}
