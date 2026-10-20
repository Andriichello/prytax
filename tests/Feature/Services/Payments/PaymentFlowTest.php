<?php

namespace Tests\Feature\Services\Payments;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PryTax\Services\Payments\PaymentFlow;
use PryTax\Services\Transfers\TransferRecorder;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;

/**
 * Class PaymentFlowTest.
 *
 * The path of a receipt's money and its metrics (design.md §6.4), against the mock's receipt of 05.10.2026:
 * 3 892.50 USD invoiced, 3 877.50 landed on Wise, 1 760.00 of it moved to the ФОП account with 1 753.84 arriving.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Services\Payments
 */
class PaymentFlowTest extends TestCase
{
    use ActsInProfiles;
    use RefreshDatabase;

    /**
     * Test the fees, landing, holding and net income of the mock's receipt.
     *
     * @return void
     */
    public function testComputesTheMetricsOfTheMocksReceipt(): void
    {
        Storage::fake('local');
        $profile = $this->createProfile();
        $wise = $this->createAccount($profile);
        $bank = $this->createAccount($profile, ['kind' => 'fop_bank', 'name' => 'ПриватБанк ФОП USD']);
        $this->cacheRates(['USD' => ['2026-10-05' => '42.2297']]);
        $invoice = $this->createFrozenInvoice($profile, $wise);
        $payment = $this->recordReceipt($profile, $invoice, $wise, ['received_amount' => '3877.50']);
        $this->inProfile($profile, fn () => app(TransferRecorder::class)->record($profile, [
            'from_account_id' => $wise->id,
            'to_account_id' => $bank->id,
            'amount_sent' => '1760.00',
            'amount_received' => '1753.84',
            'received_at' => '2026-10-20',
            'allocations' => [['payment_id' => $payment->id, 'amount' => '1760.00']],
        ]));

        $flow = $this->inProfile($profile, fn () => app(PaymentFlow::class)->forPayment($payment->fresh()));

        $this->assertSame('164379.11', $payment->uah_amount);
        $this->assertSame('8218.96', $payment->single_tax_uah);
        $this->assertSame('1643.79', $payment->military_levy_uah);
        $this->assertSame('15.00', $flow['receipt_fee']);
        $this->assertSame('6.16', $flow['transfer_fee_share']);
        $this->assertSame('21.16', $flow['fees']);
        $this->assertSame('2117.50', $flow['still_on_platform']);
        $this->assertSame('1753.84', $flow['landed_on_fop_bank'][0]['amount']);
        $this->assertSame('3637.79', $flow['net_income']);
        $this->assertSame(['receipt', 'transfer'], array_column($flow['steps'], 'type'));
    }
}
