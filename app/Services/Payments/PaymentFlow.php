<?php

namespace PryTax\Services\Payments;

use Brick\Math\BigDecimal;
use Carbon\CarbonInterface;
use PryTax\Enums\AccountKind;
use PryTax\Helpers\Decimal;
use PryTax\Models\Account;
use PryTax\Models\Payment;
use PryTax\Models\TransferAllocation;
use PryTax\Services\Rates\NbuRateProvider;
use PryTax\Services\Transfers\HoldingCalculator;
use Throwable;

/**
 * Class PaymentFlow.
 *
 * The path of a receipt's money ("Шлях коштів", design.md §6.4): where it landed, the transfers that carried it on,
 * where it is now, and its metrics, in the receipt's currency:
 *
 * - `receipt_fee = amount − received_amount` (same currency; else null);
 * - `transfer_fee_share = Σ alloc / amount_sent × fee` over the transfers;
 * - `landed_on_fop_bank`: what reached ФОП accounts in Ukraine;
 * - `still_on_platform`: what the receipt still holds on its platform, a cash position, not a loss;
 * - `net_income = received_amount − transfer_fee_share − (ЄП + ВЗ) / nbu_rate`, without ЄСВ, which belongs to no
 *   receipt.
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
        $payment->loadMissing(['receivedToAccount', 'allocations.transfer.fromAccount', 'allocations.transfer.toAccount']);

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
        $payment->loadMissing(['receivedToAccount', 'allocations.transfer.toAccount']);

        $receiptFee = $payment->received_currency === $payment->currency
            ? Decimal::of($payment->amount)->minus(Decimal::of($payment->received_amount))
            : null;

        $transferFees = Decimal::zero();
        $landed = [];

        if ($payment->receivedToAccount?->kind === AccountKind::FopBank) {
            $landed[$payment->received_to_account_id] = Decimal::of($payment->received_amount);
        }

        foreach ($payment->allocations as $allocation) {
            $transfer = $allocation->transfer;
            $transferFees = $transferFees->plus(
                $this->inPaymentCurrency($payment, $this->holdings->feeShare($allocation), $transfer->currency_sent, $transfer->received_at)
            );

            if ($transfer->toAccount?->kind === AccountKind::FopBank) {
                $landed[$transfer->to_account_id] = ($landed[$transfer->to_account_id] ?? Decimal::zero())
                    ->plus($this->holdings->arrived($allocation));
            }
        }

        $taxes = Decimal::divide(
            Decimal::of($payment->single_tax_uah)->plus(Decimal::of($payment->military_levy_uah)),
            $payment->nbu_rate
        );

        $stillOnPlatform = $payment->receivedToAccount?->kind === AccountKind::Platform
            ? $this->holdings->holding($payment, $payment->received_to_account_id)
            : Decimal::zero();

        $landedAccounts = Account::query()->whereKey(array_keys($landed))->get()->keyBy('id');

        return [
            'receipt_fee' => Decimal::moneyOrNull($receiptFee),
            'transfer_fee_share' => Decimal::money($transferFees),
            'fees' => Decimal::money(($receiptFee ?? Decimal::zero())->plus($transferFees)),
            'landed_on_fop_bank' => collect($landed)->map(fn (BigDecimal $amount, int $id) => [
                ...$this->account($landedAccounts->get($id)),
                'amount' => Decimal::money($amount),
            ])->values()->all(),
            'still_on_platform' => Decimal::money($stillOnPlatform),
            'taxes_in_currency' => Decimal::money($taxes),
            'net_income' => Decimal::money(
                Decimal::of($payment->received_amount)->minus($transferFees)->minus($taxes)
            ),
        ];
    }

    /**
     * Convert an amount of a transfer's currency into the receipt's currency: as it is, through the receipt's rate
     * for UAH, or through the NBU rates of the transfer's date for another currency (zero, when unavailable).
     *
     * @param Payment $payment
     * @param BigDecimal $amount
     * @param string $currency
     * @param CarbonInterface $date
     *
     * @return BigDecimal
     */
    protected function inPaymentCurrency(Payment $payment, BigDecimal $amount, string $currency, $date): BigDecimal
    {
        if ($currency === $payment->currency) {
            return $amount;
        }

        if ($currency === 'UAH') {
            return Decimal::divide($amount, $payment->nbu_rate);
        }

        try {
            $rate = $this->rates->rate($currency, $date)->rate;
        } catch (Throwable) {
            return Decimal::zero();
        }

        return Decimal::divide($amount->multipliedBy(Decimal::of($rate)), $payment->nbu_rate);
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
