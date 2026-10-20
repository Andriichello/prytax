<?php

namespace Tests\Feature\Services\Reports;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PryTax\Services\Payments\RefundRecorder;
use PryTax\Services\Reports\ClientStats;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;

/**
 * Class ClientStatsTest.
 *
 * A client's numbers (design.md §16): paid all time, this year and last year, net of refunds by the year of each
 * receipt and refund, and what its ready and sent invoices still leave to pay.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Services\Reports
 */
class ClientStatsTest extends TestCase
{
    use ActsInProfiles;
    use RefreshDatabase;

    /**
     * Test the paid and outstanding amounts of a client, with a receipt of last year and a refund of this year.
     *
     * @return void
     */
    public function testCountsPaidByYearNetOfRefundsAndWhatIsOutstanding(): void
    {
        Storage::fake('local');
        $this->travelTo('2026-10-20 10:00:00');
        $profile = $this->createProfile();
        $wise = $this->createAccount($profile);
        $this->cacheRates(['USD' => ['2025-12-20' => '41.9000', '2026-10-05' => '42.2297', '2026-10-10' => '42.1000']]);
        $old = $this->createFrozenInvoice($profile, $wise, ['period_start' => '2025-12-01', 'period_end' => '2025-12-15']);
        $this->recordReceipt($profile, $old, $wise, ['received_at' => '2025-12-20']);
        $current = $this->createFrozenInvoice($profile, $wise, ['client_id' => $old->client_id, 'contract_id' => $old->contract_id]);
        $receipt = $this->recordReceipt($profile, $current, $wise, ['amount' => '3000.00']);
        $this->inProfile($profile, fn () => app(RefundRecorder::class)->record($profile, $receipt, [
            'amount' => '100.00', 'from_account_id' => $wise->id, 'refunded_at' => '2026-10-10',
        ]));

        $stats = $this->inProfile($profile, fn () => app(ClientStats::class)->forClient($old->client));
        $usd = collect($stats['currencies'])->firstWhere('currency', 'USD');

        $this->assertSame('6792.50', $usd['paid_all_time']);
        $this->assertSame('2900.00', $usd['paid_this_year']);
        $this->assertSame('3892.50', $usd['paid_previous_year']);
        $this->assertSame('992.50', $usd['outstanding']);
    }
}
