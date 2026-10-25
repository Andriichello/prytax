<?php

namespace Tests\Feature\Services\Taxes;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PryTax\Enums\DeclarationStatus;
use PryTax\Exceptions\PeriodLockedException;
use PryTax\Models\Payment;
use PryTax\Services\Payments\PaymentRecorder;
use PryTax\Services\Payments\RefundRecorder;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;

/**
 * Class PeriodLockTest.
 *
 * Declared quarters (design.md §8.3): declarations are cumulative, so income written into a quarter, while a later
 * quarter of its year is declared, needs a correction note too; the note is kept once; and a write, which fails, marks
 * nothing as corrected.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Services\Taxes
 */
class PeriodLockTest extends TestCase
{
    use ActsInProfiles;
    use RefreshDatabase;

    /**
     * Test that a receipt in an open quarter needs a correction note, when a later quarter of the year is declared,
     * and with one marks that quarter corrected.
     *
     * @return void
     */
    public function testRequiresACorrectionWhenALaterQuarterIsDeclared(): void
    {
        $profile = $this->createProfile();
        $wise = $this->createAccount($profile);
        $this->cacheRates(['USD' => ['2026-02-10' => '41.0000']]);
        $later = $this->declareQuarter($profile, 2026, 2);
        $receipt = [...$this->withoutInvoice($profile), 'amount' => '100.00', 'received_to_account_id' => $wise->id, 'received_at' => '2026-02-10'];

        try {
            $this->inProfile($profile, fn () => app(PaymentRecorder::class)->record($profile, $receipt));
            $this->fail('No exception was thrown.');
        } catch (PeriodLockedException $exception) {
            $this->assertSame(
                'Quarter 2 of 2026 is locked by its submitted declaration, which counts quarter 1 too. Give a correction note to change it.',
                $exception->getMessage()
            );
        }
        $this->inProfile($profile, fn () => app(PaymentRecorder::class)->record($profile, [...$receipt, 'correction_note' => 'Забутий бонус']));

        $this->assertSame(DeclarationStatus::Corrected, $later->fresh()->declaration_status);
    }

    /**
     * Test that moving a receipt within its declared quarter keeps the correction note once.
     *
     * @return void
     */
    public function testKeepsTheCorrectionNoteOnce(): void
    {
        $profile = $this->createProfile();
        $wise = $this->createAccount($profile);
        $this->cacheRates(['USD' => ['2026-05-06' => '41.3478', '2026-05-07' => '41.3500']]);
        $payment = $this->inProfile($profile, fn () => app(PaymentRecorder::class)->record($profile, [
            ...$this->withoutInvoice($profile), 'amount' => '100.00', 'received_to_account_id' => $wise->id, 'received_at' => '2026-05-06',
        ]));
        $period = $this->declareQuarter($profile, 2026, 2);
        $this->travelTo('2026-09-15 10:00:00');

        $this->inProfile($profile, fn () => app(PaymentRecorder::class)->update($profile, $payment, [
            'received_at' => '2026-05-07', 'correction_note' => 'Виправлено дату',
        ]));

        $this->assertSame('15.09.2026: Виправлено дату', $period->fresh()->notes);
    }

    /**
     * Test that a refund in a declared quarter, which fails for want of a rate, marks the quarter nothing.
     *
     * @return void
     */
    public function testMarksNothingCorrectedByAWriteThatFails(): void
    {
        Http::fake(['bank.gov.ua/*' => Http::response('Service Unavailable', 503)]);
        $profile = $this->createProfile();
        $wise = $this->createAccount($profile);
        $payment = $this->inProfile($profile, fn () => Payment::factory()->create([
            'profile_id' => $profile->id, 'received_to_account_id' => $wise->id, 'received_at' => '2026-04-10',
        ]));
        $period = $this->declareQuarter($profile, 2026, 2);

        try {
            $this->inProfile($profile, fn () => app(RefundRecorder::class)->record($profile, $payment, [
                'amount' => '100.00', 'from_account_id' => $wise->id, 'refunded_at' => '2026-05-10', 'correction_note' => 'Повернення',
            ]));
            $this->fail('No exception was thrown.');
        } catch (ValidationException) {
            $this->assertSame(DeclarationStatus::Accepted, $period->fresh()->declaration_status);
            $this->assertNull($period->fresh()->notes);
        }
    }
}
