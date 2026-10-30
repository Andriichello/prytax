<?php

namespace PryTax\Services\Transfers;

use Carbon\CarbonImmutable;
use PryTax\Helpers\Decimal;
use PryTax\Models\Account;
use PryTax\Models\Payment;
use PryTax\Models\Profile;
use PryTax\Services\Payments\RepatriationRule;

/**
 * Class UntransferredReceipts.
 *
 * The receipts, whose money is still on a platform or a foreign bank (the accounts, from which it is to be moved to a
 * Ukrainian ФОП account, design.md §6.7), which Головна shows as needing attention: what each holds where, and by when
 * the profile's repatriation rule wants it moved, with the totals per currency. The repatriation notification of
 * `AlertCollector` comes of the same holdings, once past that date.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Services\Transfers
 */
class UntransferredReceipts
{
    /**
     * UntransferredReceipts constructor.
     *
     * @param HoldingCalculator $holdings
     * @param RepatriationRule $repatriation
     */
    public function __construct(
        protected HoldingCalculator $holdings,
        protected RepatriationRule $repatriation
    ) {}

    /**
     * Get the profile's receipts with money on a platform or a foreign bank, the oldest first, one row per receipt and
     * account, with the totals per currency.
     *
     * @param Profile $profile
     *
     * @return array{receipts: array<int, array<string, mixed>>, totals: array<int, array<string, mixed>>}
     */
    public function of(Profile $profile): array
    {
        $today = CarbonImmutable::today();

        $receipts = Account::query()->get()
            ->filter(fn (Account $account) => $account->needsRepatriation())
            ->flatMap(fn (Account $account) => $this->holdings->holdingsOn($account)
                ->map(function (array $holding) use ($account, $profile, $today) {
                    /** @var Payment $payment */
                    $payment = $holding['payment'];
                    $deadline = $this->repatriation->deadline($profile, $payment->received_at);

                    return [
                        'payment_id' => $payment->getKey(),
                        'received_at' => $payment->received_at->toDateString(),
                        'amount' => Decimal::money($holding['amount']),
                        'currency' => $account->currency,
                        'account' => ['id' => $account->getKey(), 'name' => $account->name],
                        'invoice' => $payment->invoice === null ? null : [
                            'id' => $payment->invoice->getKey(),
                            'number' => $payment->invoice->number,
                            'source' => $payment->invoice->source->value,
                            'client' => $payment->invoice->client?->name,
                        ],
                        'deadline' => $deadline?->toDateString(),
                        'overdue' => $deadline !== null && $deadline->lt($today),
                    ];
                }))
            ->sortBy([['received_at', 'asc'], ['payment_id', 'asc']])
            ->values();

        $totals = $receipts->groupBy('currency')
            ->map(fn ($rows, string $currency) => [
                'currency' => $currency,
                'count' => $rows->count(),
                'amount' => Decimal::money(Decimal::sum($rows->pluck('amount'))),
                'overdue' => Decimal::money(Decimal::sum($rows->where('overdue', true)->pluck('amount'))),
            ])
            ->sortKeys()
            ->values();

        return ['receipts' => $receipts->all(), 'totals' => $totals->all()];
    }
}
