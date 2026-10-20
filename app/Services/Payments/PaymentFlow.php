<?php

namespace PryTax\Services\Payments;

use Brick\Math\BigDecimal;
use Carbon\CarbonInterface;
use PryTax\Enums\AccountKind;
use PryTax\Enums\PaymentKind;
use PryTax\Helpers\Decimal;
use PryTax\Models\Account;
use PryTax\Models\Payment;
use PryTax\Models\TransferAllocation;
use PryTax\Services\Rates\NbuRateProvider;
use PryTax\Services\Transfers\HoldingCalculator;

/**
 * Class PaymentFlow.
 *
 * The path of a receipt's money ("Шлях коштів", design.md §6.4): where it landed, the transfers that carried it on,
 * where it is now, and its metrics, in the receipt's currency:
 *
 * - `receipt_fee = amount − received_amount` (same currency; else null);
 * - `receipt_fx_spread = amount × nbu_rate − received_amount` in UAH, when a foreign receipt landed in UAH (else
 *   null): the platform's conversion, a loss like a fee;
 * - `transfer_fee_share = Σ alloc / amount_sent × fee` over the transfers;
 * - `landed_on_fop_bank`: what reached ФОП accounts in Ukraine;
 * - `still_on_platform`: what the receipt still holds on its platform, a cash position, not a loss;
 * - `net_income = received − transfer_fee_share − (ЄП + ВЗ) / nbu_rate`, where `received` is `received_amount` in
 *   the receipt's currency, without ЄСВ, which belongs to no receipt.
 *
 * An FX gain brings no money of its own, so it has no fees, and its net income is the gain less its taxes. Amounts in
 * other currencies are converted at cached NBU rates only, never by calling the NBU while reading: an amount, whose
 * rate isn't cached, leaves its metric unknown (null).
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Services\Payments
 */
class PaymentFlow
{
    /**
     * PaymentFlow constructor.
     *
     * @param HoldingCalculator $holdings
     * @param NbuRateProvider $rates
     */
    public function __construct(
        protected HoldingCalculator $holdings,
        protected NbuRateProvider $rates
    ) {}

    /**
     * Get the path and the metrics of the receipt's money.
     *
     * @param Payment $payment
     *
     * @return array<string, mixed>
     */
    public function forPayment(Payment $payment): array
    {
        $payment->loadMissing(['receivedToAccount', 'allocations.transfer.fromAccount', 'allocations.transfer.toAccount', 'refunds']);

        $metrics = $this->metrics($payment);
        $accounts = Account::query()->whereKey(array_keys($this->holdings->holdingsOf($payment)))->get()->keyBy('id');

        $steps = [[
            'type' => 'receipt',
            'date' => $payment->received_at->toDateString(),
            'account' => $this->account($payment->receivedToAccount),
            'amount' => $payment->received_amount,
            'currency' => $payment->received_currency,
            'fee' => $metrics['receipt_fee'],
        ]];

        $allocations = $payment->allocations->sortBy(fn (TransferAllocation $allocation) => $allocation->transfer->received_at);

        foreach ($allocations as $allocation) {
            $transfer = $allocation->transfer;

            $steps[] = [
                'type' => 'transfer',
                'transfer_id' => $transfer->getKey(),
                'date' => $transfer->received_at->toDateString(),
                'from' => $this->account($transfer->fromAccount),
                'to' => $this->account($transfer->toAccount),
                'allocated' => $allocation->amount,
                'currency_sent' => $transfer->currency_sent,
                'arrived' => Decimal::money($this->holdings->arrived($allocation)),
                'currency_received' => $transfer->currency_received,
                'fee_share' => Decimal::money($this->holdings->feeShare($allocation)),
            ];
        }

        foreach ($payment->refunds->sortBy('refunded_at') as $refund) {
            $steps[] = [
                'type' => 'refund',
                'refund_id' => $refund->getKey(),
                'date' => $refund->refunded_at->toDateString(),
                'from' => $this->account($refund->fromAccount),
                'amount' => $refund->amount,
                'currency' => $refund->currency,
            ];
        }

        $holdings = collect($this->holdings->holdingsOf($payment))
            ->filter(fn (BigDecimal $amount) => $amount->isPositive())
            ->map(fn (BigDecimal $amount, int $id) => [
                ...$this->account($accounts->get($id)),
                'amount' => Decimal::money($amount),
            ])
            ->values()
            ->all();

        return [
            'payment_id' => $payment->getKey(),
            'currency' => $payment->currency,
            'steps' => $steps,
            'holdings' => $holdings,
            ...$metrics,
        ];
    }

    /**
     * Get the metrics of the receipt: its fees, where its money landed and is, and its net income.
     *
     * @param Payment $payment
     *
     * @return array<string, mixed>
     */
    public function metrics(Payment $payment): array
    {
        $payment->loadMissing(['receivedToAccount', 'allocations.transfer.toAccount', 'refunds']);

        $taxes = Decimal::divide(
            Decimal::of($payment->single_tax_uah)->plus(Decimal::of($payment->military_levy_uah)),
            $payment->nbu_rate
        );

        if ($payment->kind === PaymentKind::FxGain) {
            return [
                'receipt_fee' => null,
                'receipt_fx_spread' => null,
                'transfer_fee_share' => '0.00',
                'fees' => '0.00',
                'landed_on_fop_bank' => [],
                'still_on_platform' => '0.00',
                'taxes_in_currency' => Decimal::money($taxes),
                'net_income' => Decimal::money(Decimal::of($payment->amount)->minus($taxes)),
            ];
        }

        $receiptFee = $payment->received_currency === $payment->currency
            ? Decimal::of($payment->amount)->minus(Decimal::of($payment->received_amount))
            : null;

        $receiptSpread = $payment->received_currency === 'UAH' && $payment->currency !== 'UAH'
            ? Decimal::times($payment->amount, $payment->nbu_rate)->minus(Decimal::of($payment->received_amount))
            : null;

        $transferFees = Decimal::zero();
        $landed = [];

        if ($payment->receivedToAccount?->kind === AccountKind::FopBank) {
            $landed[$payment->received_to_account_id] = Decimal::of($payment->received_amount);
        }

        foreach ($payment->allocations as $allocation) {
            $transfer = $allocation->transfer;
            $share = $this->inPaymentCurrency($payment, $this->holdings->feeShare($allocation), $transfer->currency_sent, $transfer->received_at);
            $transferFees = $transferFees === null || $share === null ? null : $transferFees->plus($share);

            if ($transfer->toAccount?->kind === AccountKind::FopBank) {
                $landed[$transfer->to_account_id] = ($landed[$transfer->to_account_id] ?? Decimal::zero())
                    ->plus($this->holdings->arrived($allocation));
            }
        }

        $stillOnPlatform = $payment->receivedToAccount?->kind === AccountKind::Platform
            ? $this->holdings->holding($payment, $payment->received_to_account_id)
            : Decimal::zero();

        $received = $this->inPaymentCurrency(
            $payment,
            Decimal::of($payment->received_amount),
            $payment->received_currency,
            $payment->received_at
        );

        $landedAccounts = Account::query()->whereKey(array_keys($landed))->get()->keyBy('id');

        return [
            'receipt_fee' => Decimal::moneyOrNull($receiptFee),
            'receipt_fx_spread' => Decimal::moneyOrNull($receiptSpread),
            'transfer_fee_share' => Decimal::moneyOrNull($transferFees),
            'fees' => Decimal::moneyOrNull($transferFees?->plus($receiptFee ?? Decimal::zero())),
            'landed_on_fop_bank' => collect($landed)->map(fn (BigDecimal $amount, int $id) => [
                ...$this->account($landedAccounts->get($id)),
                'amount' => Decimal::money($amount),
            ])->values()->all(),
            'still_on_platform' => Decimal::money($stillOnPlatform),
            'taxes_in_currency' => Decimal::money($taxes),
            'net_income' => Decimal::moneyOrNull(
                $received === null || $transferFees === null ? null : $received->minus($transferFees)->minus($taxes)
            ),
        ];
    }

    /**
     * Convert an amount into the receipt's currency: as it is, through the receipt's rate for UAH, or through the
     * cached NBU rates of the date for another currency, or null, when that rate isn't cached.
     *
     * @param Payment $payment
     * @param BigDecimal $amount
     * @param string $currency
     * @param CarbonInterface $date
     *
     * @return BigDecimal|null
     */
    protected function inPaymentCurrency(Payment $payment, BigDecimal $amount, string $currency, $date): ?BigDecimal
    {
        if ($currency === $payment->currency) {
            return $amount;
        }

        if ($currency === 'UAH') {
            return Decimal::divide($amount, $payment->nbu_rate);
        }

        $rate = $this->rates->cached($currency, $date)?->rate;

        return $rate === null ? null : Decimal::divide($amount->multipliedBy(Decimal::of($rate)), $payment->nbu_rate);
    }

    /**
     * Describe an account in the path.
     *
     * @param Account|null $account
     *
     * @return array{account_id: int|null, account_name: string|null, kind: string|null, currency: string|null}
     */
    protected function account(?Account $account): array
    {
        return [
            'account_id' => $account?->getKey(),
            'account_name' => $account?->name,
            'kind' => $account?->kind->value,
            'currency' => $account?->currency,
        ];
    }
}
