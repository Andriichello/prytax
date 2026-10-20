<?php

namespace Tests\Feature\Services\Payments;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PryTax\Models\Payment;
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

    /**
     * Test that a receipt in USD, which landed on a UAH account, has its conversion as a spread in UAH, and its net
     * income in USD, from what landed converted at its rate.
     *
     * @return void
     */
    public function testComputesTheSpreadAndNetOfAReceiptLandingInUah(): void
    {
        $profile = $this->createProfile();
        $bank = $this->createAccount($profile, ['kind' => 'fop_bank', 'currency' => 'UAH', 'name' => 'ПриватБанк ФОП']);
        $payment = $this->inProfile($profile, fn () => Payment::factory()->create([
            'profile_id' => $profile->id, 'received_to_account_id' => $bank->id,
            'received_amount' => '40500.00', 'received_currency' => 'UAH',
        ]));

        $metrics = $this->inProfile($profile, fn () => app(PaymentFlow::class)->metrics($payment));

        $this->assertNull($metrics['receipt_fee']);
        $this->assertSame('500.00', $metrics['receipt_fx_spread']);
        $this->assertSame('60.00', $metrics['taxes_in_currency']);
        $this->assertSame('927.80', $metrics['net_income']);
    }

    /**
     * Test that an FX gain, which brings no money of its own, has no fees, and nets the gain less its taxes.
     *
     * @return void
     */
    public function testComputesTheNetOfAnFxGainWithoutFees(): void
    {
        $profile = $this->createProfile();
        $bank = $this->createAccount($profile, ['kind' => 'fop_bank', 'currency' => 'UAH', 'name' => 'ПриватБанк ФОП']);
        $gain = $this->inProfile($profile, fn () => Payment::factory()->create([
            'profile_id' => $profile->id, 'kind' => 'fx_gain', 'currency' => 'UAH', 'amount' => '500.00',
            'received_to_account_id' => $bank->id, 'received_amount' => '0.00', 'nbu_rate' => '1.0000',
            'uah_amount' => '500.00', 'single_tax_uah' => '25.00', 'military_levy_uah' => '5.00',
        ]));

        $metrics = $this->inProfile($profile, fn () => app(PaymentFlow::class)->metrics($gain));

        $this->assertNull($metrics['receipt_fee']);
        $this->assertSame('0.00', $metrics['fees']);
        $this->assertSame('470.00', $metrics['net_income']);
    }
}
