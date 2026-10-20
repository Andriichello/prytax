<?php

namespace PryTax\Services\Reports;

use Carbon\CarbonImmutable;
use PryTax\Helpers\Decimal;
use PryTax\Models\Client;
use PryTax\Models\Invoice;
use PryTax\Models\Payment;
use PryTax\Models\Refund;

/**
 * Class ClientStats.
 *
 * The numbers of a client's page (design.md §16), per currency: paid all-time, this year and last year, the amounts
 * of its receipts less refunds, by the year of `received_at` / `refunded_at`; and outstanding, `Σ (total − paid)`
 * over its ready and sent invoices, with the overdue part of it.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Services\Reports
 */
class ClientStats
{
    /**
     * Get the numbers of the client.
     *
     * @param Client $client
     * @param CarbonImmutable|null $today
     *
     * @return array<string, mixed>
     */
    public function forClient(Client $client, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $currencies = [];

        $add = function (string $currency, string $field, $amount) use (&$currencies) {
            $currencies[$currency] ??= [
                'currency' => $currency,
                'paid_all_time' => Decimal::zero(),
                'paid_this_year' => Decimal::zero(),
                'paid_previous_year' => Decimal::zero(),
                'outstanding' => Decimal::zero(),
                'overdue' => Decimal::zero(),
            ];

            $currencies[$currency][$field] = $currencies[$currency][$field]->plus(Decimal::of((string) $amount));
        };

        $paid = Payment::query()
            ->whereHas('invoice', fn ($query) => $query->where('client_id', $client->getKey()))
            ->groupBy('currency', 'period_year')
            ->selectRaw('currency, period_year, SUM(amount) as total')
            ->get();

        foreach ($paid as $row) {
            $this->bucket($add, $row->currency, (int) $row->period_year, (string) $row->total, $today);
        }

        $refunded = Refund::query()
            ->whereHas('payment.invoice', fn ($query) => $query->where('client_id', $client->getKey()))
            ->groupBy('currency', 'period_year')
            ->selectRaw('currency, period_year, SUM(amount) as total')
            ->get();

        foreach ($refunded as $row) {
            $this->bucket($add, $row->currency, (int) $row->period_year, (string) Decimal::of((string) $row->total)->negated(), $today);
        }

        $open = Invoice::query()->open()->withPaidAmount()->where('client_id', $client->getKey())->get();

        foreach ($open as $invoice) {
            $left = Decimal::max(Decimal::of($invoice->total)->minus(Decimal::of($invoice->paid_amount)), 0);
            $add($invoice->currency, 'outstanding', $left);

            if ($invoice->due_at !== null && $invoice->due_at->lt($today)) {
                $add($invoice->currency, 'overdue', $left);
            }
        }

        $since = collect([
            $client->contracts()->min('started_at'),
            $client->invoices()->min('issued_at'),
        ])->filter()->min();

        return [
            'client_id' => $client->getKey(),
            'year' => $today->year,
            'since' => $since ? CarbonImmutable::parse($since)->toDateString() : null,
            'invoices_count' => $client->invoices()->count(),
            'currencies' => array_values(array_map(
                fn (array $currency) => array_map(fn ($value) => is_string($value) ? $value : Decimal::money($value), $currency),
                $currencies
            )),
        ];
    }

    /**
     * Add an amount paid in a year to its buckets.
     *
     * @param callable $add
     * @param string $currency
     * @param int $year
     * @param string $amount
     * @param CarbonImmutable $today
     *
     * @return void
     */
    protected function bucket(callable $add, string $currency, int $year, string $amount, CarbonImmutable $today): void
    {
        $add($currency, 'paid_all_time', $amount);

        if ($year === $today->year) {
            $add($currency, 'paid_this_year', $amount);
        }

        if ($year === $today->year - 1) {
            $add($currency, 'paid_previous_year', $amount);
        }
    }
}
