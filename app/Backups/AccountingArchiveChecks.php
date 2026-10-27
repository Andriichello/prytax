<?php

namespace PryTax\Backups;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use PryTax\DataLifecycle\Backup\RunSpec;
use PryTax\DataLifecycle\Contracts\ArchiveChecks;

/**
 * Class AccountingArchiveChecks.
 *
 * The figures of a profile, which its archive's manifest keeps and an import computes again (backups.md §2.6, §5.1):
 * income and refunds in UAH by quarter, invoice totals by currency and the taxes paid, summed by the database, so an
 * import that lost or changed a row fails. A period export keeps the figures of its period.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Backups
 */
final class AccountingArchiveChecks implements ArchiveChecks
{
    /**
     * Compute the figures of the profile.
     *
     * @param int $tenantId
     * @param ConnectionInterface $db
     * @param RunSpec $spec
     *
     * @return array{income_uah_by_quarter: array<string, string>, refunds_uah_by_quarter: array<string, string>, invoices_total_by_currency: array<string, string>, tax_payments_paid_uah: string}
     */
    public function compute(int $tenantId, ConnectionInterface $db, RunSpec $spec): array
    {
        $byQuarter = fn (string $table, string $date) => $this->inPeriod($db->table($table), $date, $spec)
            ->where('profile_id', $tenantId)
            ->selectRaw("CONCAT(YEAR($date), '-Q', QUARTER($date)) AS quarter, SUM(uah_amount) AS total")
            ->groupBy('quarter')
            ->orderBy('quarter')
            ->pluck('total', 'quarter')
            ->all();

        return [
            'income_uah_by_quarter' => $byQuarter('payments', 'received_at'),
            'refunds_uah_by_quarter' => $byQuarter('refunds', 'refunded_at'),
            'invoices_total_by_currency' => $this->inPeriod($db->table('invoices'), 'issued_at', $spec)
                ->where('profile_id', $tenantId)
                ->selectRaw('currency, SUM(total) AS total')
                ->groupBy('currency')
                ->orderBy('currency')
                ->pluck('total', 'currency')
                ->all(),
            'tax_payments_paid_uah' => (string) ($this->inPeriod($db->table('tax_payments'), 'paid_at', $spec)
                ->where('profile_id', $tenantId)
                ->where('status', 'paid')
                ->sum('amount') ?: '0.00'),
        ];
    }

    /**
     * Limit the query to the run's period, when it has one.
     *
     * @param Builder $query
     * @param string $date
     * @param RunSpec $spec
     *
     * @return Builder
     */
    private function inPeriod(Builder $query, string $date, RunSpec $spec): Builder
    {
        return $spec->isPeriod() ? $query->whereBetween($date, [$spec->from->toDateString(), $spec->to->toDateString()]) : $query;
    }
}
