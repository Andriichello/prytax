<?php

namespace PryTax\Services\Reports;

use Illuminate\Support\Collection;
use PryTax\Helpers\Decimal;
use PryTax\Models\Invoice;
use PryTax\Models\Payment;
use PryTax\Models\Profile;
use PryTax\Models\Refund;
use PryTax\Services\Payments\PaymentFlow;
use PryTax\Services\Taxes\DeadlineScheduler;
use PryTax\Services\Taxes\EsvObligationScheduler;
use PryTax\Services\Taxes\TaxLiabilityCalculator;

/**
 * Class AccountingView.
 *
 * The quarter of Облік (design.md §7, §16): its receipts and refunds by `received_at` / `refunded_at`, each with its
 * invoice and client, the original amount and its NBU rate, the UAH amount, the per-receipt ЄП and ВЗ, and its fees
 * and net in the receipt's currency; the invoices of the rows, for grouping by invoice; the quarter's declaration
 * status and deadlines; and the summary of its liabilities with the cross-checks.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Services\Reports
 */
class AccountingView
{
    /**
     * AccountingView constructor.
     *
     * @param TaxLiabilityCalculator $liabilities
     * @param DeadlineScheduler $deadlines
     * @param EsvObligationScheduler $esv
     * @param PaymentFlow $flows
     */
    public function __construct(
        protected TaxLiabilityCalculator $liabilities,
        protected DeadlineScheduler $deadlines,
        protected EsvObligationScheduler $esv,
        protected PaymentFlow $flows
    ) {}

    /**
     * Get the quarter of the profile's accounting.
     *
     * @param Profile $profile
     * @param int $year
     * @param int $quarter
     *
     * @return array{period: array<string, mixed>, rows: array<int, array<string, mixed>>, invoices: array<int, array<string, mixed>>, summary: array<string, mixed>}
     */
    public function forQuarter(Profile $profile, int $year, int $quarter): array
    {
        $period = $this->deadlines->ensure($profile, $year, $quarter);
        $this->esv->ensureYear($profile, $year);

        $rows = $this->rows($year, $quarter);

        $invoices = Invoice::query()
            ->withPaidAmount()
            ->whereKey($rows->pluck('invoice_id')->filter()->unique()->all())
            ->with('client')
            ->get()
            ->map(fn (Invoice $invoice) => [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'status' => $invoice->status->value,
                'client_id' => $invoice->client_id,
                'client_name' => $invoice->client->name,
                'currency' => $invoice->currency,
                'total' => $invoice->total,
                'paid_amount' => $invoice->paid_amount,
                'remaining' => Decimal::money(Decimal::max(Decimal::of($invoice->total)->minus(Decimal::of($invoice->paid_amount)), 0)),
            ])
            ->values();

        return [
            'period' => [
                'id' => $period->id,
                'year' => $year,
                'quarter' => $quarter,
                'declaration_status' => $period->declaration_status->value,
                'declaration_due_at' => $period->declaration_due_at->toDateString(),
                'tax_due_at' => $period->tax_due_at->toDateString(),
                'esv_due_at' => $period->esv_due_at->toDateString(),
                'is_locked' => $period->isLocked(),
            ],
            'rows' => $rows->all(),
            'invoices' => $invoices->all(),
            'summary' => $this->liabilities->forQuarter($profile, $year, $quarter),
        ];
    }

    /**
     * Get the rows of the quarter: its receipts and its refunds (negative), by date.
     *
     * @param int $year
     * @param int $quarter
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(int $year, int $quarter): Collection
    {
        $payments = Payment::query()
            ->inQuarter($year, $quarter)
            ->chronologically()
            ->with(['invoice.client', 'receivedToAccount', 'allocations.transfer.toAccount'])
            ->get()
            ->map(function (Payment $payment) {
                $metrics = $this->flows->metrics($payment);

                return [
                    'type' => 'payment',
                    'id' => $payment->id,
                    'payment_id' => $payment->id,
                    'kind' => $payment->kind->value,
                    'date' => $payment->received_at->toDateString(),
                    'month' => $payment->period_month,
                    'invoice_id' => $payment->invoice_id,
                    'invoice_number' => $payment->invoice?->number,
                    'client_name' => $payment->invoice?->client?->name,
                    'description' => $payment->description,
                    'currency' => $payment->currency,
                    'amount' => $payment->amount,
                    'nbu_rate' => $payment->nbu_rate,
                    'uah_amount' => $payment->uah_amount,
                    'single_tax_uah' => $payment->single_tax_uah,
                    'military_levy_uah' => $payment->military_levy_uah,
                    'account_id' => $payment->received_to_account_id,
                    'account_name' => $payment->receivedToAccount?->name,
                    'fees' => $metrics['fees'],
                    'net' => Decimal::money(
                        Decimal::of($payment->amount)->minus(Decimal::of($metrics['fees']))->minus(Decimal::of($metrics['taxes_in_currency']))
                    ),
                ];
            });

        $refunds = Refund::query()
            ->where('period_year', $year)
            ->where('period_quarter', $quarter)
            ->with(['payment.invoice.client', 'fromAccount'])
            ->get()
            ->map(fn (Refund $refund) => [
                'type' => 'refund',
                'id' => $refund->id,
                'payment_id' => $refund->payment_id,
                'kind' => 'refund',
                'date' => $refund->refunded_at->toDateString(),
                'month' => $refund->period_month,
                'invoice_id' => $refund->payment?->invoice_id,
                'invoice_number' => $refund->payment?->invoice?->number,
                'client_name' => $refund->payment?->invoice?->client?->name,
                'description' => $refund->reason,
                'currency' => $refund->currency,
                'amount' => Decimal::money(Decimal::of($refund->amount)->negated()),
                'nbu_rate' => $refund->nbu_rate,
                'uah_amount' => Decimal::money(Decimal::of($refund->uah_amount)->negated()),
                'single_tax_uah' => null,
                'military_levy_uah' => null,
                'account_id' => $refund->from_account_id,
                'account_name' => $refund->fromAccount?->name,
                'fees' => null,
                'net' => null,
            ]);

        return $payments->concat($refunds)->sortBy([['date', 'asc'], ['id', 'asc']])->values();
    }
}
