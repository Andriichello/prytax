<?php

namespace PryTax\Services\Transfers;

use Carbon\CarbonInterface;
use PryTax\Helpers\Decimal;
use PryTax\Models\Account;

/**
 * Class AllocationProposer.
 *
 * Proposes which receipts a transfer out of an account carries (design.md §6.3): an exact match, when one receipt
 * holds exactly the amount sent (the common case of one transfer per receipt), and a FIFO proposal over the
 * receipts, which hold money on the account, the oldest first, capped at the amount. Receipts dated after the
 * transfer arrived aren't proposed, as a transfer can't move money received later. The user edits either before
 * saving; what stays unallocated remains on the account, it isn't lost.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Services\Transfers
 */
class AllocationProposer
{
    /**
     * AllocationProposer constructor.
     *
     * @param HoldingCalculator $holdings
     */
    public function __construct(
        protected HoldingCalculator $holdings
    ) {}

    /**
     * Propose the allocations of a transfer of the amount out of the account.
     *
     * @param Account $from
     * @param string $amountSent
     * @param int|null $excludeTransferId The transfer being edited, whose own allocations are left out.
     * @param CarbonInterface|null $receivedAt When the transfer arrived: receipts of later days aren't proposed.
     *
     * @return array{
     *     available: string,
     *     candidates: array<int, array<string, mixed>>,
     *     exact: array<int, array{payment_id: int, amount: string}>|null,
     *     fifo: array<int, array{payment_id: int, amount: string}>,
     *     unallocated: string,
     * }
     */
    public function propose(
        Account $from,
        string $amountSent,
        ?int $excludeTransferId = null,
        ?CarbonInterface $receivedAt = null
    ): array {
        $holdings = $this->holdings->holdingsOn($from, $excludeTransferId)
            ->filter(fn (array $holding) => $receivedAt === null || $holding['payment']->received_at->lte($receivedAt))
            ->values();
        $amount = Decimal::of($amountSent);

        $exact = $holdings->first(fn (array $holding) => $holding['amount']->isEqualTo($amount));

        $fifo = [];
        $left = $amount;

        foreach ($holdings as $holding) {
            if (!$left->isPositive()) {
                break;
            }

            $take = Decimal::min($holding['amount'], $left);
            $fifo[] = ['payment_id' => $holding['payment']->getKey(), 'amount' => Decimal::money($take)];
            $left = $left->minus($take);
        }

        return [
            'available' => Decimal::money(Decimal::sum($holdings->pluck('amount'))),
            'candidates' => $holdings->map(fn (array $holding) => [
                'payment_id' => $holding['payment']->getKey(),
                'received_at' => $holding['payment']->received_at->toDateString(),
                'invoice_number' => $holding['payment']->invoice?->number,
                'client_name' => $holding['payment']->invoice?->client?->name,
                'description' => $holding['payment']->description,
                'holding' => Decimal::money($holding['amount']),
            ])->all(),
            'exact' => $exact ? [['payment_id' => $exact['payment']->getKey(), 'amount' => Decimal::money($amount)]] : null,
            'fifo' => $fifo,
            'unallocated' => Decimal::money(Decimal::max($left, 0)),
        ];
    }
}
