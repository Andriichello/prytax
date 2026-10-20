<?php

namespace Tests\Feature\Services\Transfers;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PryTax\Enums\DeclarationStatus;
use PryTax\Exceptions\PeriodLockedException;
use PryTax\Models\Account;
use PryTax\Models\AccountTransfer;
use PryTax\Models\Payment;
use PryTax\Models\Profile;
use PryTax\Services\Transfers\AllocationProposer;
use PryTax\Services\Transfers\HoldingCalculator;
use PryTax\Services\Transfers\TransferMetrics;
use PryTax\Services\Transfers\TransferRecorder;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;

/**
 * Class TransferRecorderTest.
 *
 * Transfers between own accounts (design.md §6.2–6.5): holdings are pro-rata of what arrived on each hop, so a fee
 * or a currency change bounds the next hop; a same-currency fee is the gap; allocations never exceed what a receipt
 * holds; and an FX sale above the NBU rate makes an FX gain, when the profile counts it.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Services\Transfers
 */
class TransferRecorderTest extends TestCase
{
    use ActsInProfiles;
    use RefreshDatabase;

    /**
     * Create a profile with a $1 000 receipt on Payoneer.
     *
     * @return array{0: Profile, 1: Payment, 2: Account}
     */
    private function receiptOnPayoneer(): array
    {
        $profile = $this->createProfile();
        $payoneer = $this->createAccount($profile, ['name' => 'Payoneer USD']);
        $payment = $this->inProfile($profile, fn () => Payment::factory()->create([
            'profile_id' => $profile->id,
            'received_to_account_id' => $payoneer->id,
        ]));

        return [$profile, $payment, $payoneer];
    }

    /**
     * Record a transfer in the profile.
     *
     * @param Profile $profile
     * @param Account $from
     * @param Account $to
     * @param string $sent
     * @param string $received
     * @param array $allocations
     * @param array $data
     *
     * @return AccountTransfer
     */
    private function transfer(Profile $profile, Account $from, Account $to, string $sent, string $received, array $allocations, array $data = [])
    {
        return $this->inProfile($profile, fn () => app(TransferRecorder::class)->record($profile, [
            'from_account_id' => $from->id,
            'to_account_id' => $to->id,
            'amount_sent' => $sent,
            'amount_received' => $received,
            'received_at' => '2026-10-10',
            'allocations' => $allocations,
            ...$data,
        ]));
    }

    /**
     * Test that a $1 000 receipt moved with a $15 fee holds $985 on the next account, and nothing on the first.
     *
     * @return void
     */
    public function testHoldsWhatArrivedAfterAFeeHop(): void
    {
        [$profile, $payment, $payoneer] = $this->receiptOnPayoneer();
        $wise = $this->createAccount($profile);

        $transfer = $this->transfer($profile, $payoneer, $wise, '1000.00', '985.00', [['payment_id' => $payment->id, 'amount' => '1000.00']]);
        $holdings = $this->inProfile($profile, fn () => app(HoldingCalculator::class)->holdingsOf($payment->fresh()));

        $this->assertSame('15.00', $transfer->fee_amount);
        $this->assertSame('USD', $transfer->fee_currency);
        $this->assertSame('0.00', (string) $holdings[$payoneer->id]);
        $this->assertSame('985.00', (string) $holdings[$wise->id]);
    }

    /**
     * Test that the next hop out of an account can't take more than the receipt holds there after a fee.
     *
     * @return void
     */
    public function testRejectsAllocatingMoreThanTheHolding(): void
    {
        [$profile, $payment, $payoneer] = $this->receiptOnPayoneer();
        $wise = $this->createAccount($profile);
        $bank = $this->createAccount($profile, ['kind' => 'fop_bank', 'name' => 'Bank USD']);
        $this->transfer($profile, $payoneer, $wise, '1000.00', '985.00', [['payment_id' => $payment->id, 'amount' => '1000.00']]);

        try {
            $this->transfer($profile, $wise, $bank, '1000.00', '990.00', [['payment_id' => $payment->id, 'amount' => '1000.00']]);
            $this->fail('No exception was thrown.');
        } catch (ValidationException $exception) {
            $this->assertSame(['allocations' => ['The receipt holds only 985.00 USD on Wise USD.']], $exception->errors());
        }

        $this->assertDatabaseCount('account_transfers', 1);
    }

    /**
     * Test that a USD → EUR hop leaves a EUR holding, which bounds the next hop, whose currency is EUR.
     *
     * @return void
     */
    public function testHoldsTheReceivedCurrencyAfterACrossCurrencyHop(): void
    {
        [$profile, $payment, $payoneer] = $this->receiptOnPayoneer();
        $wiseEur = $this->createAccount($profile, ['name' => 'Wise EUR', 'currency' => 'EUR']);

        $this->transfer($profile, $payoneer, $wiseEur, '1000.00', '920.00', [['payment_id' => $payment->id, 'amount' => '1000.00']]);
        $holding = $this->inProfile($profile, fn () => app(HoldingCalculator::class)->holding($payment->fresh(), $wiseEur));

        $this->assertSame('920.00', (string) $holding);
    }

    /**
     * Test that a same-currency fee, which isn't the gap between sent and received, is refused.
     *
     * @return void
     */
    public function testRejectsASameCurrencyFeeOtherThanTheGap(): void
    {
        [$profile, $payment, $payoneer] = $this->receiptOnPayoneer();
        $wise = $this->createAccount($profile);

        $this->expectExceptionObject(ValidationException::withMessages([
            'fee_amount' => ['The fee of a same-currency transfer is the amount sent less the amount received: 15.00.'],
        ]));

        $this->transfer($profile, $payoneer, $wise, '1000.00', '985.00', [], ['fee_amount' => '10.00']);
    }

    /**
     * Test that a transfer, whose money moved on, can't be edited to bring less than was moved on.
     *
     * @return void
     */
    public function testRejectsAnEditThatLeavesADownstreamHoldingNegative(): void
    {
        [$profile, $payment, $payoneer] = $this->receiptOnPayoneer();
        $wise = $this->createAccount($profile);
        $bank = $this->createAccount($profile, ['kind' => 'fop_bank', 'name' => 'Bank USD']);
        $first = $this->transfer($profile, $payoneer, $wise, '1000.00', '985.00', [['payment_id' => $payment->id, 'amount' => '1000.00']]);
        $this->transfer($profile, $wise, $bank, '985.00', '980.00', [['payment_id' => $payment->id, 'amount' => '985.00']]);

        $this->expectException(ValidationException::class);

        $this->inProfile($profile, fn () => app(TransferRecorder::class)->update($profile, $first, ['amount_received' => '900.00']));
    }

    /**
     * Test that an exact match is proposed, when one receipt holds the amount, and FIFO caps the oldest ones.
     *
     * @return void
     */
    public function testProposesAnExactMatchAndTheOldestReceiptsFirst(): void
    {
        [$profile, $older, $payoneer] = $this->receiptOnPayoneer();
        $newer = $this->inProfile($profile, fn () => Payment::factory()->create([
            'profile_id' => $profile->id,
            'received_to_account_id' => $payoneer->id,
            'amount' => '600.00',
            'received_at' => '2026-10-07',
        ]));

        [$exact, $fifo] = $this->inProfile($profile, fn () => [
            app(AllocationProposer::class)->propose($payoneer, '600.00'),
            app(AllocationProposer::class)->propose($payoneer, '1200.00'),
        ]);

        $this->assertSame([['payment_id' => $newer->id, 'amount' => '600.00']], $exact['exact']);
        $this->assertSame('1600.00', $fifo['available']);
        $this->assertNull($fifo['exact']);
        $this->assertSame([
            ['payment_id' => $older->id, 'amount' => '1000.00'],
            ['payment_id' => $newer->id, 'amount' => '200.00'],
        ], $fifo['fifo']);
    }

    /**
     * Test that a sale of USD into a ФОП account above the NBU rate makes an FX gain receipt, when the profile counts
     * FX gains, and that deleting the transfer deletes it.
     *
     * @return void
     */
    public function testRecordsAndDeletesTheFxGainOfASale(): void
    {
        [$profile, $payment, $payoneer] = $this->receiptOnPayoneer();
        $this->inProfile($profile, fn () => $profile->updateSettings(['income.fx_gain' => 'include']));
        $bank = $this->createAccount($profile, ['kind' => 'fop_bank', 'name' => 'Bank UAH', 'currency' => 'UAH']);
        $this->cacheRates(['USD' => ['2026-10-10' => '41.0000']]);

        $transfer = $this->transfer($profile, $payoneer, $bank, '1000.00', '41500.00', [['payment_id' => $payment->id, 'amount' => '1000.00']]);
        $gain = $this->inProfile($profile, fn () => Payment::query()->where('source_transfer_id', $transfer->id)->first());

        $this->assertSame('fx_gain', $gain->kind->value);
        $this->assertSame('500.00', $gain->amount);
        $this->assertSame('500.00', $gain->uah_amount);
        $this->assertSame('0.00', $gain->received_amount);
        $this->assertSame('-500.00', $transfer->fx_spread);

        $this->inProfile($profile, fn () => app(TransferRecorder::class)->delete($profile, $transfer));

        $this->assertModelMissing($gain);
    }

    /**
     * Test that an FX transfer needs the NBU's rate of its UAH leg, or a manual one, which it keeps.
     *
     * @return void
     */
    public function testRequiresARateOfAnFxTransferWhenTheNbuIsUnavailable(): void
    {
        Http::fake(['bank.gov.ua/*' => Http::response('Service Unavailable', 503)]);
        [$profile, $payment, $payoneer] = $this->receiptOnPayoneer();
        $bank = $this->createAccount($profile, ['kind' => 'fop_bank', 'name' => 'Bank UAH', 'currency' => 'UAH']);
        $allocations = [['payment_id' => $payment->id, 'amount' => '1000.00']];

        try {
            $this->transfer($profile, $payoneer, $bank, '1000.00', '41500.00', $allocations);
            $this->fail('No exception was thrown.');
        } catch (ValidationException $exception) {
            $this->assertSame(['nbu_rate' => ['The NBU rate of USD on 10.10.2026 is unavailable. Enter the rate manually.']], $exception->errors());
        }
        $transfer = $this->transfer($profile, $payoneer, $bank, '1000.00', '41500.00', $allocations, ['nbu_rate' => '41.2000']);

        $this->assertSame('41.2000', $transfer->nbu_rate);
        $this->assertTrue($transfer->rate_is_manual);
        $this->assertDatabaseCount('exchange_rates', 0);
    }

    /**
     * Test that the spread of an FX transfer is only the gap beyond its fee, so a route's loss counts the fee once.
     *
     * @return void
     */
    public function testCountsOnlyTheGapBeyondTheFeeAsSpread(): void
    {
        [$profile, $payment, $payoneer] = $this->receiptOnPayoneer();
        $bank = $this->createAccount($profile, ['kind' => 'fop_bank', 'name' => 'Bank UAH', 'currency' => 'UAH']);
        $this->cacheRates(['USD' => ['2026-10-10' => '41.0000']]);

        $transfer = $this->transfer($profile, $payoneer, $bank, '1000.00', '40590.00', [['payment_id' => $payment->id, 'amount' => '1000.00']], [
            'fee_amount' => '10.00',
        ]);
        $stats = $this->inProfile($profile, fn () => app(TransferMetrics::class)->forYear($profile, 2026));

        $this->assertSame('0.00', $transfer->fx_spread);
        $this->assertSame('410.00', $stats['fees_uah']);
        $this->assertSame('0.00', $stats['spread_uah']);
        $this->assertSame('1.00', $stats['routes'][0]['loss_percent']);
    }

    /**
     * Test that an FX gain can't move out of its declared quarter with its transfer, while an edit, which leaves the
     * gain as it is, needs no correction note.
     *
     * @return void
     */
    public function testKeepsAnFxGainInItsDeclaredQuarter(): void
    {
        [$profile, $payment, $payoneer] = $this->receiptOnPayoneer();
        $payment->forceFill(['received_at' => '2026-09-20', 'nbu_rate_date' => '2026-09-20'])->save();
        $this->inProfile($profile, fn () => $profile->updateSettings(['income.fx_gain' => 'include']));
        $bank = $this->createAccount($profile, ['kind' => 'fop_bank', 'name' => 'Bank UAH', 'currency' => 'UAH']);
        $this->cacheRates(['USD' => ['2026-09-30' => '41.0000', '2026-10-01' => '41.0000']]);
        $transfer = $this->transfer($profile, $payoneer, $bank, '1000.00', '41500.00', [['payment_id' => $payment->id, 'amount' => '1000.00']], [
            'received_at' => '2026-09-30',
        ]);
        $period = $this->declareQuarter($profile, 2026, 3);

        $this->inProfile($profile, fn () => app(TransferRecorder::class)->update($profile, $transfer, ['note' => 'Продаж валюти']));

        try {
            $this->inProfile($profile, fn () => app(TransferRecorder::class)->update($profile, $transfer->fresh(), ['received_at' => '2026-10-01']));
            $this->fail('No exception was thrown.');
        } catch (PeriodLockedException) {
            $this->assertSame('2026-09-30', $transfer->fresh()->received_at->toDateString());
        }
        $this->assertSame(DeclarationStatus::Accepted, $period->fresh()->declaration_status);
    }

    /**
     * Test that a transfer can't carry a receipt, which arrived after it.
     *
     * @return void
     */
    public function testReturns422WhenATransferCarriesALaterReceipt(): void
    {
        [$profile, $payment, $payoneer] = $this->receiptOnPayoneer();
        $wise = $this->createAccount($profile);

        try {
            $this->transfer($profile, $payoneer, $wise, '1000.00', '985.00', [['payment_id' => $payment->id, 'amount' => '1000.00']], [
                'received_at' => '2026-10-01',
            ]);
            $this->fail('No exception was thrown.');
        } catch (ValidationException $exception) {
            $this->assertSame(['allocations' => ["A receipt of 05.10.2026 can't be moved by an earlier transfer."]], $exception->errors());
        }

        $this->assertDatabaseCount('account_transfers', 0);
    }
}
