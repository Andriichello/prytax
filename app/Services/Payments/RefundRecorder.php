<?php

namespace PryTax\Services\Payments;

use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PryTax\Enums\PaymentKind;
use PryTax\Helpers\Decimal;
use PryTax\Models\Account;
use PryTax\Models\Payment;
use PryTax\Models\Profile;
use PryTax\Models\Refund;
use PryTax\Services\Alerts\AlertSync;
use PryTax\Services\Invoices\InvoiceLifecycle;
use PryTax\Services\Taxes\PeriodLock;
use PryTax\Services\Taxes\TaxRuleResolver;
use PryTax\Services\Transfers\HoldingCalculator;

/**
 * Class RefundRecorder.
 *
 * Records money returned to a client from a receipt (design.md §6.6): at the NBU rate of `refunded_at`, in the
 * quarter of that date, which must be open. The refunds of a receipt never exceed its amount, are never earlier than
 * the receipt, and leave from an account in the receipt's currency. When the receipt's money is on that account,
 * the refund takes it from there, so it can't be transferred on any more; a refund from another account is paid
 * from other money. FX gains, which aren't client money, aren't refunded. A paid invoice, which a refund leaves
 * uncovered, returns to unpaid.
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
     * @param HoldingCalculator $holdings
     * @param PeriodLock $lock
     * @param AlertSync $alerts
     * @param InvoiceLifecycle $invoices
     */
    public function __construct(
        protected PaymentSnapshotter $snapshotter,
        protected TaxRuleResolver $rules,
        protected HoldingCalculator $holdings,
        protected PeriodLock $lock,
        protected AlertSync $alerts,
        protected InvoiceLifecycle $invoices
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
        if ($payment->kind === PaymentKind::FxGain) {
            throw ValidationException::withMessages([
                'payment_id' => [__('An FX gain isn\'t client money, so it isn\'t refunded.')],
            ]);
        }

        $amount = Decimal::of($data['amount']);
        $account = Account::query()->find($data['from_account_id'] ?? null);

        if (!$account || $account->currency !== $payment->currency) {
            throw ValidationException::withMessages([
                'from_account_id' => [__('The refund leaves from an account in :currency.', ['currency' => $payment->currency])],
            ]);
        }

        $date = CarbonImmutable::parse($data['refunded_at']);

        if ($date->lt($payment->received_at)) {
            throw ValidationException::withMessages([
                'refunded_at' => [__('A refund can\'t precede its receipt of :date.', ['date' => $payment->received_at->format('d.m.Y')])],
            ]);
        }

        [$rate, $exchangeRateId, $isManual] = $this->snapshotter->rateOf($payment->currency, $date, $data['nbu_rate'] ?? null);
        $rule = $this->rules->forDate($date, 'refunded_at');

        $refund = DB::transaction(function () use ($profile, $payment, $account, $amount, $date, $rate, $exchangeRateId, $isManual, $rule, $data) {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->getKey());

            $this->assertRefundable($payment, $account, $amount);
            $this->lock->assertOpen($profile, $date, $data['correction_note'] ?? null);

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

            $this->invoices->reopenIfUncovered($payment->invoice_id);

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
        DB::transaction(function () use ($profile, $refund, $correctionNote) {
            $this->lock->assertOpen($profile, $refund->refunded_at, $correctionNote);

            $refund->delete();
        });

        $this->alerts->syncAfterCommit($profile);
    }

    /**
     * Make sure the receipt can refund the amount: its refunds don't exceed it, and the account, when the receipt's
     * money reached it, still holds that much of it.
     *
     * @param Payment $payment
     * @param Account $account
     * @param BigDecimal $amount
     *
     * @return void
     * @throws ValidationException
     */
    protected function assertRefundable(Payment $payment, Account $account, BigDecimal $amount): void
    {
        $refunded = Decimal::of((string) $payment->refunds()->sum('amount'));

        if ($refunded->plus($amount)->isGreaterThan(Decimal::of($payment->amount))) {
            throw ValidationException::withMessages([
                'amount' => [__('Refunds can\'t exceed the receipt: :left :currency is left to refund.', [
                    'left' => Decimal::money(Decimal::of($payment->amount)->minus($refunded)),
                    'currency' => $payment->currency,
                ])],
            ]);
        }

        $holdings = $this->holdings->holdingsOf($payment);

        if (array_key_exists($account->getKey(), $holdings) && $amount->isGreaterThan($holdings[$account->getKey()])) {
            throw ValidationException::withMessages([
                'from_account_id' => [__('The receipt holds only :holding :currency on :account.', [
                    'holding' => Decimal::money($holdings[$account->getKey()]),
                    'currency' => $account->currency,
                    'account' => $account->name,
                ])],
            ]);
        }
    }
}
