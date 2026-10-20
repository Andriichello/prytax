<?php

namespace PryTax\Queries;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PryTax\Enums\InvoiceStatus;

/**
 * Class InvoiceQuery.
 *
 * Query of invoices.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Queries
 */
class InvoiceQuery extends TenantQuery
{
    /**
     * Add `paid_amount`: what the receipts of each invoice cover, net of refunds, in the invoice's currency. It is
     * derived on every read rather than stored, so it can't drift from the receipts.
     *
     * @return static
     */
    public function withPaidAmount(): static
    {
        if (empty($this->getQuery()->columns)) {
            $this->select($this->qualifyColumn('*'));
        }

        $table = $this->getModel()->getTable();

        $received = DB::table('payments')
            ->selectRaw('COALESCE(SUM(payments.amount), 0)')
            ->whereColumn('payments.invoice_id', "$table.id");

        $refunded = DB::table('refunds')
            ->join('payments', 'payments.id', '=', 'refunds.payment_id')
            ->selectRaw('COALESCE(SUM(refunds.amount), 0)')
            ->whereColumn('payments.invoice_id', "$table.id");

        $this->selectRaw(
            "(({$received->toSql()}) - ({$refunded->toSql()})) as paid_amount",
            [...$received->getBindings(), ...$refunded->getBindings()]
        );

        return $this;
    }

    /**
     * Only the open invoices: frozen and not yet paid or cancelled.
     *
     * @return static
     */
    public function open(): static
    {
        $this->whereIn($this->qualifyColumn('status'), [InvoiceStatus::Ready->value, InvoiceStatus::Sent->value]);

        return $this;
    }

    /**
     * Only the invoices issued in any of the periods: years (`2026`), quarters (`2026-Q4`) and months (`2026-10`),
     * in any mix.
     *
     * @param array<int, string> $periods
     * @param string $boolean
     *
     * @return static
     * @throws InvalidArgumentException When a period isn't one of the forms.
     */
    public function issuedInPeriods(array $periods, string $boolean = 'and'): static
    {
        $column = $this->qualifyColumn('issued_at');

        $this->where(function ($query) use ($periods, $column) {
            foreach ($periods as $period) {
                [$from, $to] = static::rangeOfPeriod(trim((string) $period));

                $query->orWhereBetween($column, [$from, $to]);
            }
        }, null, null, $boolean);

        return $this;
    }

    /**
     * Get the first and the last day of a period: a year (`2026`), a quarter (`2026-Q4`) or a month (`2026-10`).
     *
     * @param string $period
     *
     * @return array{0: string, 1: string}
     * @throws InvalidArgumentException When the period isn't one of the forms.
     */
    public static function rangeOfPeriod(string $period): array
    {
        if (preg_match('/^(\d{4})$/', $period, $match)) {
            return ["{$match[1]}-01-01", "{$match[1]}-12-31"];
        }

        if (preg_match('/^(\d{4})-Q([1-4])$/i', $period, $match)) {
            $start = sprintf('%s-%02d-01', $match[1], ((int) $match[2] - 1) * 3 + 1);

            return [$start, date('Y-m-t', strtotime("$start +2 months"))];
        }

        if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $period, $match)) {
            $start = "{$match[1]}-{$match[2]}-01";

            return [$start, date('Y-m-t', strtotime($start))];
        }

        throw new InvalidArgumentException(
            "The period '$period' must be a year, a quarter or a month, such as 2026, 2026-Q4 or 2026-10."
        );
    }
}
