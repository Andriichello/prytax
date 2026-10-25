<?php

namespace Tests\Feature\Http\Controllers;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use PHPUnit\Framework\Attributes\DataProvider;
use PryTax\Models\Payment;
use PryTax\Models\Profile;
use PryTax\Services\Taxes\TaxPaymentRecorder;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;

/**
 * Class TaxPaymentControllerTest.
 *
 * Tax payments (design.md §8.4): ЄП and ВЗ are paid from a receipt at its own amounts, once each unless an attempt
 * failed; ЄСВ is paid for a month with its amount typed; a sent or paid payment has its date, a paid one isn't dated in
 * the future; a payment from a foreign-currency account keeps what left it, which its balance loses. Each refusal is
 * a 422, never a failure of the database.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Http\Controllers
 */
class TaxPaymentControllerTest extends TestCase
{
    use ActsInProfiles;
    use RefreshDatabase;

    /**
     * Create a receipt of the profile: USD 1 000.00 at 41.0000 on 05.10.2026, whose ЄП is 2 050.00 and ВЗ 410.00.
     *
     * @param Profile $profile
     *
     * @return Payment
     */
    private function receipt(Profile $profile): Payment
    {
        return $this->inProfile($profile, fn () => Payment::factory()->create(['profile_id' => $profile->id]));
    }

    /**
     * Inputs of ЄСВ, which miss what its payment needs (fields left out, and fields changed), with the field refused.
     *
     * @return array<string, array{0: array<int, string>, 1: array<string, mixed>, 2: string}>
     */
    public static function incompleteEsvPayments(): array
    {
        return [
            'a month without its year' => [['period_year'], [], 'period_year'],
            'a payment without its month' => [['period_month'], [], 'period_month'],
            'a payment without its amount' => [['amount'], [], 'amount'],
            'a paid one without its date' => [['paid_at'], [], 'paid_at'],
            'a sent one without its date' => [['paid_at'], ['status' => 'sent'], 'paid_at'],
            'a paid one in the future' => [[], ['paid_at' => '2026-10-21'], 'paid_at'],
        ];
    }

    /**
     * Test that an incomplete payment of ЄСВ is refused with 422, and nothing is saved.
     *
     * @param array<int, string> $leftOut
     * @param array<string, mixed> $change
     * @param string $field
     *
     * @return void
     */
    #[DataProvider('incompleteEsvPayments')]
    public function testReturns422ForAnIncompleteEsvPayment(array $leftOut, array $change, string $field): void
    {
        $this->travelTo('2026-10-20 10:00:00');
        $profile = $this->createProfile();
        $this->actAs($profile, 'accountant');

        $payment = Arr::except([
            'tax_type' => 'social_contribution', 'period_year' => 2026, 'period_month' => 9,
            'amount' => '1902.34', 'status' => 'paid', 'paid_at' => '2026-10-20', ...$change,
        ], $leftOut);

        $this->postJson($this->profilePath($profile, 'tax-payments'), $payment)->assertUnprocessable()->assertJsonValidationErrors($field);

        $this->assertDatabaseCount('tax_payments', 0);
    }

    /**
     * Test that a payment of ЄСВ is allocated to its month.
     *
     * @return void
     */
    public function testRecordsAnEsvPaymentForItsMonth(): void
    {
        $profile = $this->createProfile();
        $this->actAs($profile, 'accountant');

        $this->postJson($this->profilePath($profile, 'tax-payments'), [
            'tax_type' => 'social_contribution', 'allocation' => 'quarter', 'period_year' => 2026, 'period_month' => 10,
            'amount' => '2000.00', 'status' => 'sent', 'paid_at' => '2026-10-19',
        ])->assertCreated()
            ->assertJsonPath('data.allocation', 'month')
            ->assertJsonPath('data.period_quarter', 4)
            ->assertJsonPath('data.amount', '2000.00');
    }

    /**
     * Test that a receipt's ЄП is paid at the receipt's own amount, in its quarter.
     *
     * @return void
     */
    public function testPaysAReceiptsTaxAtItsOwnAmount(): void
    {
        $profile = $this->createProfile();
        $this->actAs($profile, 'accountant');
        $receipt = $this->receipt($profile);

        $this->postJson($this->profilePath($profile, 'tax-payments'), [
            'tax_type' => 'single_tax', 'payment_id' => $receipt->id, 'status' => 'sent', 'paid_at' => '2026-10-12',
        ])->assertCreated()
            ->assertJsonPath('data.allocation', 'payment')
            ->assertJsonPath('data.amount', '2050.00')
            ->assertJsonPath('data.period_year', 2026)
            ->assertJsonPath('data.period_quarter', 4)
            ->assertJsonPath('data.period_month', 10);
    }

    /**
     * Test that ЄП and ВЗ are refused without a receipt or with a typed amount, and ЄСВ with a receipt (422).
     *
     * @return void
     */
    public function testReturns422ForATaxPaidOtherThanItsWay(): void
    {
        $profile = $this->createProfile();
        $this->actAs($profile, 'accountant');
        $receipt = $this->receipt($profile);
        $paid = ['status' => 'sent', 'paid_at' => '2026-10-12'];

        $this->postJson($this->profilePath($profile, 'tax-payments'), ['tax_type' => 'single_tax', 'amount' => '100.00', ...$paid])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payment_id', 'amount']);
        $this->postJson($this->profilePath($profile, 'tax-payments'), ['tax_type' => 'military_levy', 'payment_id' => $receipt->id, 'amount' => '410.00', ...$paid])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');
        $this->postJson($this->profilePath($profile, 'tax-payments'), [
            'tax_type' => 'social_contribution', 'payment_id' => $receipt->id, 'period_year' => 2026, 'period_month' => 10, 'amount' => '1902.34', ...$paid,
        ])->assertUnprocessable()->assertJsonValidationErrors('payment_id');

        $this->assertDatabaseCount('tax_payments', 0);
    }

    /**
     * Test that a receipt's tax is paid once, while a failed attempt leaves room for the next one (422 otherwise).
     *
     * @return void
     */
    public function testReturns422ForASecondPaymentOfAReceiptsTax(): void
    {
        $profile = $this->createProfile();
        $this->actAs($profile, 'accountant');
        $receipt = $this->receipt($profile);
        $payment = ['tax_type' => 'single_tax', 'payment_id' => $receipt->id, 'paid_at' => '2026-08-11'];

        $this->postJson($this->profilePath($profile, 'tax-payments'), [...$payment, 'status' => 'failed'])->assertCreated();
        $this->postJson($this->profilePath($profile, 'tax-payments'), [...$payment, 'status' => 'paid', 'paid_at' => '2026-08-12'])->assertCreated();
        $this->postJson($this->profilePath($profile, 'tax-payments'), [...$payment, 'status' => 'sent'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payment_id' => 'This tax of the receipt is paid already: change that payment instead.']);

        $this->assertDatabaseCount('tax_payments', 2);
    }

    /**
     * Test that a tax paid from a foreign-currency account needs what left it, which the account's balance loses,
     * while the tax is counted in hryvnias.
     *
     * @return void
     */
    public function testKeepsWhatLeftAForeignCurrencyAccount(): void
    {
        $profile = $this->createProfile();
        $this->actAs($profile, 'accountant');
        $receipt = $this->receipt($profile);
        $monobank = $this->createAccount($profile, ['kind' => 'fop_bank', 'currency' => 'USD', 'name' => 'Monobank ФОП']);
        $payment = ['tax_type' => 'single_tax', 'payment_id' => $receipt->id, 'status' => 'paid', 'paid_at' => '2026-10-12', 'paid_from_account_id' => $monobank->id];

        $this->postJson($this->profilePath($profile, 'tax-payments'), $payment)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['original_amount' => 'Enter the amount in USD, which left the account.']);
        $this->postJson($this->profilePath($profile, 'tax-payments'), [...$payment, 'original_amount' => '49.40'])
            ->assertCreated()
            ->assertJsonPath('data.amount', '2050.00')
            ->assertJsonPath('data.original_amount', '49.40')
            ->assertJsonPath('data.original_currency', 'USD');

        $this->getJson($this->profilePath($profile, "accounts/{$monobank->id}?append=balance"))
            ->assertOk()
            ->assertJsonPath('data.balance', '-49.40');
    }

    /**
     * Test that marking a failed payment paid without its date is refused with 422.
     *
     * @return void
     */
    public function testReturns422WhenMarkingAPaymentPaidWithoutItsDate(): void
    {
        $profile = $this->createProfile();
        $this->actAs($profile, 'accountant');
        $id = $this->postJson($this->profilePath($profile, 'tax-payments'), [
            'tax_type' => 'social_contribution', 'period_year' => 2026, 'period_month' => 10, 'amount' => '1902.34', 'status' => 'failed',
        ])->assertCreated()->json('data.id');

        $this->patchJson($this->profilePath($profile, "tax-payments/$id"), [
            'tax_type' => 'social_contribution', 'period_year' => 2026, 'period_month' => 10, 'amount' => '1902.34', 'status' => 'paid',
        ])->assertUnprocessable()->assertJsonValidationErrors('paid_at');
    }

    /**
     * Test that the history of a year's tax payments lists them newest first, with the receipt's invoice and client
     * and the account, and leaves out the other years.
     *
     * @return void
     */
    public function testListsTheYearsTaxPaymentsNewestFirst(): void
    {
        $profile = $this->createProfile();
        $this->actAs($profile, 'viewer');
        $receipt = $this->receipt($profile);
        $esv = ['tax_type' => 'social_contribution', 'amount' => '1902.34', 'status' => 'paid', 'period_year' => 2026];
        $ids = $this->inProfile($profile, fn () => [
            'september' => app(TaxPaymentRecorder::class)->record($profile, [...$esv, 'allocation' => 'month', 'period_month' => 8, 'paid_at' => '2026-09-19'])->id,
            'october' => app(TaxPaymentRecorder::class)->record($profile, [
                'tax_type' => 'single_tax', 'allocation' => 'payment', 'payment_id' => $receipt->id, 'status' => 'sent', 'paid_at' => '2026-10-12',
            ])->id,
            'last year' => app(TaxPaymentRecorder::class)->record($profile, [...$esv, 'allocation' => 'month', 'period_year' => 2025, 'period_month' => 12, 'paid_at' => '2026-01-19'])->id,
            'december' => app(TaxPaymentRecorder::class)->record($profile, [...$esv, 'allocation' => 'month', 'period_year' => 2025, 'period_month' => 11, 'paid_at' => '2025-12-19'])->id,
        ]);

        $response = $this->getJson($this->profilePath($profile, 'tax-payments?filter[paid_at][gte]=2026-01-01&filter[paid_at][lte]=2026-12-31'
            . '&sort=-paid_at,-id&include=payment.invoice.client,paidFromAccount'));

        $response->assertOk()->assertJsonPath('data.0.payment.invoice.client.id', $receipt->invoice->client_id);
        $this->assertSame([$ids['october'], $ids['september'], $ids['last year']], array_column($response->json('data'), 'id'));
    }
}
