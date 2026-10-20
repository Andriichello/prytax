<?php

namespace PryTax\Services\Transfers;

use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;
use PryTax\Helpers\Decimal;
use PryTax\Models\Account;
use PryTax\Models\Payment;
use PryTax\Models\TransferAllocation;

/**
 * Class HoldingCalculator.
 *
 * What of a receipt's money is on each account (design.md §6.2), in the account's currency:
 *
 * ```
 * holding(P, X) = received_amount, if P landed on X
 *               + Σ over transfers T into X: ROUND(alloc(T, P) / T.amount_sent × T.amount_received, 2)
 *               − Σ over transfers T out of X: alloc(T, P)
 * ```
 *
 * So a $1 000 receipt moved from Payoneer to Wise with a $15 fee holds $985 on Wise, and a USD → EUR hop leaves a
 * EUR holding, which bounds the next hop.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Services\Transfers
 */
class HoldingCalculator
{
    /**
     * Get the receipt's holdings on every account, which its money reached, by id of the account.
     *
     * @param Payment $payment
     * @param int|null $excludeTransferId A transfer to leave out, such as the one being edited.
     *
     * @return array<int, BigDecimal>
     */
    public function holdingsOf(Payment $payment, ?int $excludeTransferId = null): array
    {
        $holdings = [$payment->received_to_account_id => Decimal::of($payment->received_amount)];

        $allocations = $payment->relationLoaded('allocations')
            ? $payment->allocations
            : $payment->allocations()->with('transfer')->get();

        /** @var TransferAllocation $allocation */
        foreach ($allocations as $allocation) {
            $transfer = $allocation->transfer;

            if ($transfer === null || $transfer->getKey() === $excludeTransferId) {
                continue;
            }

            $from = $transfer->from_account_id;
            $to = $transfer->to_account_id;

            $holdings[$from] = ($holdings[$from] ?? Decimal::zero())->minus(Decimal::of($allocation->amount));
            $holdings[$to] = ($holdings[$to] ?? Decimal::zero())->plus($this->arrived($allocation));
        }

        return $holdings;
    }

    /**
     * Get the receipt's holding on the account.
     *
     * @param Payment $payment
     * @param Account|int $account
     * @param int|null $excludeTransferId
     *
     * @return BigDecimal
     */
    public function holding(Payment $payment, Account|int $account, ?int $excludeTransferId = null): BigDecimal
    {
        $id = $account instanceof Account ? $account->getKey() : $account;

        return $this->holdingsOf($payment, $excludeTransferId)[$id] ?? Decimal::zero();
    }

    /**
     * Get what of an allocation arrived on the transfer's target account: its pro-rata share of `amount_received`.
     *
     * @param TransferAllocation $allocation
     *
     * @return BigDecimal
     */
    public function arrived(TransferAllocation $allocation): BigDecimal
    {
        $transfer = $allocation->transfer;

        return Decimal::divide(
            Decimal::of($allocation->amount)->multipliedBy(Decimal::of($transfer->amount_received)),
            $transfer->amount_sent
        );
    }

    /**
     * Get the allocation's share of the transfer's fee, in the transfer's `currency_sent`.
     *
     * @param TransferAllocation $allocation
     *
     * @return BigDecimal
     */
    public function feeShare(TransferAllocation $allocation): BigDecimal
    {
        $transfer = $allocation->transfer;

        return Decimal::divide(
            Decimal::of($allocation->amount)->multipliedBy(Decimal::of($transfer->fee_amount)),
            $transfer->amount_sent
        );
    }

    /**
     * Get the receipts, which hold money on the account, with what each holds, the oldest first.
     *
     * @param Account $account
     * @param int|null $excludeTransferId
     *
     * @return Collection<int, array{payment: Payment, amount: BigDecimal}>
     */
    public function holdingsOn(Account $account, ?int $excludeTransferId = null): Collection
    {
        return Payment::query()
            ->where(fn ($query) => $query
                ->where('received_to_account_id', $account->getKey())
                ->orWhereHas('allocations.transfer', fn ($transfers) => $transfers->where('to_account_id', $account->getKey())))
            ->with(['allocations.transfer', 'invoice.client'])
            ->chronologically()
            ->get()
            ->map(fn (Payment $payment) => [
                'payment' => $payment,
                'amount' => $this->holding($payment, $account, $excludeTransferId),
            ])
            ->filter(fn (array $holding) => $holding['amount']->isPositive())
            ->values();
    }
}
