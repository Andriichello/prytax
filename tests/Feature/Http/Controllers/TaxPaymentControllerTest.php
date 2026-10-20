<?php

namespace Tests\Feature\Http\Controllers;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;

/**
 * Class TaxPaymentControllerTest.
 *
 * Tax payments (design.md §3, §8.2): a payment of a month or a quarter names its year, a sent or paid one its date,
 * a paid one isn't dated in the future, and each is a 422, never a failure of the database.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Http\Controllers
 */
class TaxPaymentControllerTest extends TestCase
{
    use ActsInProfiles;
    use RefreshDatabase;

    /**
     * Inputs, which miss what a tax payment needs (fields left out, and fields changed), with the error each answers.
     *
     * @return array<string, array{0: array<int, string>, 1: array<string, mixed>, 2: array<string, string>}>
     */
    public static function incompletePayments(): array
    {
        return [
            'a quarter without its year' => [['period_year'], [], ['period_year' => 'The year is required.']],
            'a paid one without its date' => [['paid_at'], [], ['paid_at' => 'The date of the payment is required.']],
            'a sent one without its date' => [['paid_at'], ['status' => 'sent'], ['paid_at' => 'The date of the payment is required.']],
            'a paid one in the future' => [[], ['paid_at' => '2026-10-21'], ['paid_at' => "A paid tax payment can't be dated in the future."]],
        ];
    }

    /**
     * Test that an incomplete tax payment is refused with 422, and nothing is saved.
     *
     * @param array<int, string> $leftOut
     * @param array<string, mixed> $change
     * @param array<string, string> $errors
     *
     * @return void
     */
    #[DataProvider('incompletePayments')]
    public function testReturns422ForAnIncompleteTaxPayment(array $leftOut, array $change, array $errors): void
    {
        $this->travelTo('2026-10-20 10:00:00');
        $profile = $this->createProfile();
        $this->actAs($profile, 'accountant');

        $payment = Arr::except([
            'tax_type' => 'single_tax', 'allocation' => 'quarter', 'period_year' => 2026, 'period_quarter' => 3,
            'amount' => '25155.93', 'status' => 'paid', 'paid_at' => '2026-10-20', ...$change,
        ], $leftOut);

        $this->postJson($this->profilePath($profile, 'tax-payments'), $payment)->assertUnprocessable()->assertJsonValidationErrors($errors);

        $this->assertDatabaseCount('tax_payments', 0);
    }

    /**
     * Test that marking a sent payment paid without its date is refused with 422.
     *
     * @return void
     */
    public function testReturns422WhenMarkingAPaymentPaidWithoutItsDate(): void
    {
        $profile = $this->createProfile();
        $this->actAs($profile, 'accountant');
        $id = $this->postJson($this->profilePath($profile, 'tax-payments'), [
            'tax_type' => 'military_levy', 'allocation' => 'month', 'period_year' => 2026, 'period_month' => 10,
            'amount' => '1643.79', 'status' => 'failed',
        ])->assertCreated()->json('data.id');

        $this->patchJson($this->profilePath($profile, "tax-payments/$id"), ['status' => 'paid'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['paid_at' => 'The date of the payment is required.']);
    }
}
