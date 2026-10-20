<?php

namespace PryTax\Services\Payments;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PryTax\Helpers\Decimal;
use PryTax\Models\Account;
use PryTax\Models\Payment;
use PryTax\Models\Profile;
use PryTax\Models\Refund;
use PryTax\Services\Alerts\AlertSync;
use PryTax\Services\Taxes\PeriodLock;
use PryTax\Services\Taxes\TaxRuleResolver;

/**
 * Class RefundRecorder.
 *
 * Records money returned to a client from a receipt (design.md §6.6): at the NBU rate of `refunded_at`, in the
 * quarter of that date, which must be open. The refunds of a receipt never exceed its amount, and leave from an
 * account in the receipt's currency.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Services\Payments
 */
class RefundRecorder
{
    /**
     * RefundRecorder constructor.
     *
     * @param PaymentSnapshotter $snapshotter
     * @param TaxRuleResolver $rules
     * @param PeriodLock $lock
     * @param AlertSync $alerts
     */
    public function __construct(
        protected PaymentSnapshotter $snapshotter,
        protected TaxRuleResolver $rules,
        protected PeriodLock $lock,
        protected AlertSync $alerts
    ) {}

    /**
     * Record a refund of the receipt.
     *
     * @param Profile $profile
     * @param Payment $payment
     * @param array $data
     *
     * @return Refund
     * @throws ValidationException
     */
    public function record(Profile $profile, Payment $payment, array $data): Refund
    {
        $amount = Decimal::of($data['amount']);
        $refunded = Decimal::of((string) $payment->refunds()->sum('amount'));

        if ($refunded->plus($amount)->isGreaterThan(Decimal::of($payment->amount))) {
            throw ValidationException::withMessages([
                'amount' => [__('Refunds can\'t exceed the receipt: :left :currency is left to refund.', [
                    'left' => Decimal::money(Decimal::of($payment->amount)->minus($refunded)),
                    'currency' => $payment->currency,
                ])],
            ]);
        }

        $account = Account::query()->find($data['from_account_id'] ?? null);

        if (!$account || $account->currency !== $payment->currency) {
            throw ValidationException::withMessages([
                'from_account_id' => [__('The refund leaves from an account in :currency.', ['currency' => $payment->currency])],
            ]);
        }

        $date = CarbonImmutable::parse($data['refunded_at']);
        $this->lock->assertOpen($profile, $date, $data['correction_note'] ?? null);

        [$rate, $exchangeRateId, $isManual] = $this->snapshotter->rateOf($payment->currency, $date, $data['nbu_rate'] ?? null);
        $rule = $this->rules->forDate($date, 'refunded_at');

        $refund = DB::transaction(function () use ($profile, $payment, $account, $amount, $date, $rate, $exchangeRateId, $isManual, $rule, $data) {
            $refund = new Refund;
            $refund->forceFill([
                'profile_id' => $profile->getKey(),
                'payment_id' => $payment->getKey(),
                'from_account_id' => $account->getKey(),
                'amount' => Decimal::money($amount),
                'currency' => $payment->currency,
                'refunded_at' => $date->toDateString(),
                'nbu_rate' => $rate,
                'nbu_rate_date' => $date->toDateString(),
                'exchange_rate_id' => $exchangeRateId,
                'rate_is_manual' => $isManual,
                'uah_amount' => Decimal::money(Decimal::times($amount, $rate)),
                'tax_rule_id' => $rule->getKey(),
                'reason' => $data['reason'] ?? null,
            ])->save();

            return $refund;
        });

        $this->alerts->syncAfterCommit($profile);

        return $refund->refresh();
    }

    /**
     * Delete the refund, when its quarter is open.
     *
     * @param Profile $profile
     * @param Refund $refund
     * @param string|null $correctionNote
     *
     * @return void
     */
    public function delete(Profile $profile, Refund $refund, ?string $correctionNote = null): void
    {
        $this->lock->assertOpen($profile, $refund->refunded_at, $correctionNote);

        DB::transaction(fn () => $refund->delete());

        $this->alerts->syncAfterCommit($profile);
    }
}
