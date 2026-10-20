<?php

namespace PryTax\Services\Accounts;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PryTax\Enums\MovementDirection;
use PryTax\Enums\TaxPaymentStatus;
use PryTax\Helpers\Decimal;
use PryTax\Models\Account;
use PryTax\Models\AccountMovement;
use PryTax\Models\AccountTransfer;
use PryTax\Models\Payment;
use PryTax\Models\Profile;
use PryTax\Models\Refund;
use PryTax\Models\TaxPayment;
use PryTax\Services\Payments\RepatriationRule;
use PryTax\Services\Transfers\HoldingCalculator;

/**
 * Class AccountStatement.
 *
 * What the account screen shows (design.md §16): its balance and limit, the year's inbound ("Надходження") and
 * outbound ("Перекази") flows, the receipts, whose money is still on it (flagged, when it should have reached a ФОП
 * account already), and the history of its movements, newest first.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Services\Accounts
 */
class AccountStatement
{
    /**
     * AccountStatement constructor.
     *
     * @param AccountBalanceCalculator $balances
     * @param AccountLimitCalculator $limits
     * @param HoldingCalculator $holdings
     * @param RepatriationRule $repatriation
     */
    public function __construct(
        protected AccountBalanceCalculator $balances,
        protected AccountLimitCalculator $limits,
        protected HoldingCalculator $holdings,
        protected RepatriationRule $repatriation
    ) {}

    /**
     * Get the statement of the account in the year.
     *
     * @param Profile $profile
     * @param Account $account
     * @param int $year
     *
     * @return array<string, mixed>
     */
    public function forYear(Profile $profile, Account $account, int $year): array
    {
        $from = CarbonImmutable::create($year);
        $to = $from->endOfYear()->startOfDay();
        $today = CarbonImmutable::today();

        $holdings = $this->holdings->holdingsOn($account)->map(function (array $holding) use ($profile, $account, $today) {
            /** @var Payment $payment */
            $payment = $holding['payment'];
            $deadline = $account->needsRepatriation() ? $this->repatriation->deadline($profile, $payment->received_at) : null;

            return [
                'payment_id' => $payment->getKey(),
                'received_at' => $payment->received_at->toDateString(),
                'invoice_id' => $payment->invoice_id,
                'invoice_number' => $payment->invoice?->number,
                'client_name' => $payment->invoice?->client?->name,
                'description' => $payment->description,
                'amount' => Decimal::money($holding['amount']),
                'repatriation_deadline' => $deadline?->toDateString(),
                'is_late' => $deadline !== null && $deadline->lt($today) && $payment->repatriation_ack_at === null,
            ];
        });

        return [
            'account_id' => $account->getKey(),
            'year' => $year,
            'currency' => $account->currency,
            'balance' => $this->balances->balance($account),
            'limit' => $this->limits->progress($account),
            'flows' => $this->balances->flows($account, $from, $to),
            'holdings' => $holdings->values()->all(),
            'holdings_total' => Decimal::money(Decimal::sum($holdings->pluck('amount'))),
            'history' => $this->history($account, $from, $to)->all(),
        ];
    }

    /**
     * Get the movements of the account in the range, newest first: each with its date, kind, signed amount and what
     * it relates to.
     *
     * @param Account $account
     * @param CarbonImmutable $from
     * @param CarbonImmutable $to
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function history(Account $account, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $range = [$from->toDateString(), $to->toDateString()];
        $rows = collect();

        Payment::query()
            ->where('received_to_account_id', $account->getKey())
            ->whereBetween('received_at', $range)
            ->where('received_amount', '>', 0)
            ->with('invoice.client')
            ->get()
            ->each(fn (Payment $payment) => $rows->push([
                'type' => 'receipt',
                'date' => $payment->received_at->toDateString(),
                'amount' => Decimal::money($payment->received_amount),
                'fee' => $payment->received_currency === $payment->currency
                    ? Decimal::money(Decimal::of($payment->amount)->minus(Decimal::of($payment->received_amount)))
                    : null,
                'payment_id' => $payment->getKey(),
                'invoice_number' => $payment->invoice?->number,
                'title' => $payment->invoice?->client?->name ?? $payment->description,
            ]));

        // A transfer arrives on its received_at, and leaves on its sent_at, when that is known
        AccountTransfer::query()
            ->where(fn ($query) => $query
                ->where(fn ($inbound) => $inbound->where('to_account_id', $account->getKey())->whereBetween('received_at', $range))
                ->orWhere(fn ($outbound) => $outbound->where('from_account_id', $account->getKey())
                    ->whereBetween(DB::raw('COALESCE(sent_at, received_at)'), $range)))
            ->with(['fromAccount', 'toAccount'])
            ->get()
            ->each(function (AccountTransfer $transfer) use ($rows, $account) {
                $inbound = $transfer->to_account_id === $account->getKey();

                $rows->push([
                    'type' => $inbound ? 'transfer_in' : 'transfer_out',
                    'date' => ($inbound ? $transfer->received_at : ($transfer->sent_at ?? $transfer->received_at))->toDateString(),
                    'amount' => $inbound ? $transfer->amount_received : Decimal::money(Decimal::of($transfer->amount_sent)->negated()),
                    'fee' => $transfer->fee_amount,
                    'transfer_id' => $transfer->getKey(),
                    'title' => $inbound ? $transfer->fromAccount->name : $transfer->toAccount->name,
                ]);
            });

        TaxPayment::query()
            ->where('paid_from_account_id', $account->getKey())
            ->where('status', '<>', TaxPaymentStatus::Failed->value)
            ->whereBetween('paid_at', $range)
            ->get()
            ->each(fn (TaxPayment $payment) => $rows->push([
                'type' => 'tax',
                'date' => $payment->paid_at->toDateString(),
                'amount' => Decimal::money(Decimal::of($payment->amount)->negated()),
                'tax_payment_id' => $payment->getKey(),
                'title' => $payment->tax_type->value,
                'status' => $payment->status->value,
            ]));

        Refund::query()
            ->where('from_account_id', $account->getKey())
            ->whereBetween('refunded_at', $range)
            ->get()
            ->each(fn (Refund $refund) => $rows->push([
                'type' => 'refund',
                'date' => $refund->refunded_at->toDateString(),
                'amount' => Decimal::money(Decimal::of($refund->amount)->negated()),
                'refund_id' => $refund->getKey(),
                'payment_id' => $refund->payment_id,
                'title' => $refund->reason,
            ]));

        AccountMovement::query()
            ->where('account_id', $account->getKey())
            ->whereBetween('booked_at', $range)
            ->get()
            ->each(fn (AccountMovement $movement) => $rows->push([
                'type' => 'movement',
                'date' => $movement->booked_at->toDateString(),
                'amount' => $movement->direction === MovementDirection::In
                    ? $movement->amount
                    : Decimal::money(Decimal::of($movement->amount)->negated()),
                'movement_id' => $movement->getKey(),
                'category' => $movement->category->value,
                'title' => $movement->note,
            ]));

        return $rows->sortByDesc('date')->values();
    }
}
