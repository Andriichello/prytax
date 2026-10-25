<?php

namespace PryTax\Services\Accounts;

use Brick\Math\BigDecimal;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Support\Facades\DB;
use PryTax\Enums\MovementDirection;
use PryTax\Enums\TaxPaymentStatus;
use PryTax\Helpers\Decimal;
use PryTax\Models\Account;
use PryTax\Models\AccountMovement;
use PryTax\Models\AccountOpeningBalance;
use PryTax\Models\AccountTransfer;
use PryTax\Models\Payment;
use PryTax\Models\Refund;
use PryTax\Models\TaxPayment;

/**
 * Class AccountBalanceCalculator.
 *
 * The balance of an account from its tracked flows, in its currency: its latest opening balance, plus what landed
 * on it (receipts, transfers in, inbound movements), less what left it (transfers out, tax payments, refunds,
 * outbound movements), from the date of that opening balance. Expenses aren't tracked in v1, so it is labelled a
 * tracked-flows balance, which needn't match the statement.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Services\Accounts
 */
class AccountBalanceCalculator
{
    /**
     * Get the balance of the account.
     *
     * @param Account $account
     *
     * @return string
     */
    public function balance(Account $account): string
    {
        $opening = $this->opening($account);
        $since = $opening?->as_of;
        $flows = $this->flows($account, $since);

        return Decimal::money(
            Decimal::of($opening?->amount)
                ->plus(Decimal::of($flows['inbound']))
                ->minus(Decimal::of($flows['outbound']))
        );
    }

    /**
     * Get the latest opening balance of the account.
     *
     * @param Account $account
     *
     * @return AccountOpeningBalance|null
     */
    public function opening(Account $account): ?AccountOpeningBalance
    {
        return AccountOpeningBalance::query()
            ->where('account_id', $account->getKey())
            ->orderByDesc('as_of')
            ->first();
    }

    /**
     * Get the inbound and outbound flows of the account in the range: receipts and transfers in ("Надходження"),
     * and transfers out, tax payments and refunds ("Перекази"), with the movements.
     *
     * @param Account $account
     * @param CarbonInterface|null $from
     * @param CarbonInterface|null $to
     *
     * @return array{inbound: string, outbound: string, receipts: string, transfers_in: string, transfers_out: string, taxes: string, refunds: string, movements_in: string, movements_out: string}
     */
    public function flows(Account $account, ?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        $range = function ($query, $column) use ($from, $to) {
            return $query
                ->when($from, fn ($query) => $query->where($column, '>=', $from->toDateString()))
                ->when($to, fn ($query) => $query->where($column, '<=', $to->toDateString()));
        };

        $sum = fn ($query, string|Expression $column, $date) => Decimal::of((string) $range($query, $date)->sum($column));

        $receipts = $sum(Payment::query()->where('received_to_account_id', $account->getKey()), 'received_amount', 'received_at');
        $transfersIn = $sum(AccountTransfer::query()->where('to_account_id', $account->getKey()), 'amount_received', 'received_at');
        // A transfer leaves on its sent_at, when that is known
        $transfersOut = $sum(
            AccountTransfer::query()->where('from_account_id', $account->getKey()),
            'amount_sent',
            DB::raw('COALESCE(sent_at, received_at)')
        );
        // A tax paid from a foreign-currency account takes what left it, which the bank sold
        $taxes = $sum(
            TaxPayment::query()
                ->where('paid_from_account_id', $account->getKey())
                ->where('status', '<>', TaxPaymentStatus::Failed->value),
            DB::raw('COALESCE(original_amount, amount)'),
            'paid_at'
        );
        $refunds = $sum(Refund::query()->where('from_account_id', $account->getKey()), 'amount', 'refunded_at');
        $movementsIn = $sum(
            AccountMovement::query()->where('account_id', $account->getKey())->where('direction', MovementDirection::In->value),
            'amount',
            'booked_at'
        );
        $movementsOut = $sum(
            AccountMovement::query()->where('account_id', $account->getKey())->where('direction', MovementDirection::Out->value),
            'amount',
            'booked_at'
        );

        return [
            'inbound' => Decimal::money($receipts->plus($transfersIn)->plus($movementsIn)),
            'outbound' => Decimal::money($transfersOut->plus($taxes)->plus($refunds)->plus($movementsOut)),
            'receipts' => Decimal::money($receipts),
            'transfers_in' => Decimal::money($transfersIn),
            'transfers_out' => Decimal::money($transfersOut),
            'taxes' => Decimal::money($taxes),
            'refunds' => Decimal::money($refunds),
            'movements_in' => Decimal::money($movementsIn),
            'movements_out' => Decimal::money($movementsOut),
        ];
    }

    /**
     * Get the net of the inbound and outbound flows.
     *
     * @param array{inbound: string, outbound: string} $flows
     *
     * @return BigDecimal
     */
    public static function net(array $flows): BigDecimal
    {
        return Decimal::of($flows['inbound'])->minus(Decimal::of($flows['outbound']));
    }
}
