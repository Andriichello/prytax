<?php

namespace PryTax\Services\Invoices;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use PryTax\Enums\InvoiceStatus;
use PryTax\Helpers\Decimal;
use PryTax\Models\Invoice;
use PryTax\Models\Payment;
use PryTax\Models\Profile;
use PryTax\Services\Alerts\AlertSync;

/**
 * Class InvoiceLifecycle.
 *
 * The status changes of a frozen invoice past its freeze (design.md §4): sending it, marking it paid once its
 * receipts cover the total (with the date of the last receipt by default), and cancelling it, whose number is never
 * reused. The model's transitions refuse the others with 409.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Services\Invoices
 */
class InvoiceLifecycle
{
    /**
     * InvoiceLifecycle constructor.
     *
     * @param AlertSync $alerts
     */
    public function __construct(
        protected AlertSync $alerts
    ) {}

    /**
     * Mark the invoice as sent on the date (today by default).
     *
     * @param Profile $profile
     * @param Invoice $invoice
     * @param string|null $sentAt
     *
     * @return Invoice
     */
    public function send(Profile $profile, Invoice $invoice, ?string $sentAt = null): Invoice
    {
        $invoice->forceFill([
            'status' => InvoiceStatus::Sent,
            'sent_at' => $sentAt ?? CarbonImmutable::today()->toDateString(),
        ])->save();

        $this->alerts->syncAfterCommit($profile);

        return $invoice->refresh();
    }

    /**
     * Mark the invoice as paid on the date (of its last receipt by default), once its receipts cover the total.
     *
     * @param Profile $profile
     * @param Invoice $invoice
     * @param string|null $paidAt
     *
     * @return Invoice
     * @throws ValidationException When the receipts don't cover the total.
     */
    public function pay(Profile $profile, Invoice $invoice, ?string $paidAt = null): Invoice
    {
        $paid = $invoice->receivedAmount();

        if ($paid->isLessThan(Decimal::of($invoice->total)) && $invoice->status !== InvoiceStatus::Paid) {
            throw ValidationException::withMessages([
                'status' => [__('The receipts cover :paid of :total :currency, so the invoice isn\'t paid yet.', [
                    'paid' => Decimal::money($paid),
                    'total' => $invoice->total,
                    'currency' => $invoice->currency,
                ])],
            ]);
        }

        $lastReceipt = Payment::query()->where('invoice_id', $invoice->getKey())->max('received_at');

        $invoice->forceFill([
            'status' => InvoiceStatus::Paid,
            'paid_at' => $paidAt ?? ($lastReceipt ? CarbonImmutable::parse($lastReceipt)->toDateString() : CarbonImmutable::today()->toDateString()),
        ])->save();

        $this->alerts->syncAfterCommit($profile);

        return $invoice->refresh();
    }

    /**
     * Cancel the invoice on the date (today by default).
     *
     * @param Profile $profile
     * @param Invoice $invoice
     * @param string|null $cancelledAt
     *
     * @return Invoice
     */
    public function cancel(Profile $profile, Invoice $invoice, ?string $cancelledAt = null): Invoice
    {
        $invoice->forceFill([
            'status' => InvoiceStatus::Cancelled,
            'cancelled_at' => $cancelledAt ?? CarbonImmutable::today()->toDateString(),
        ])->save();

        $this->alerts->syncAfterCommit($profile);

        return $invoice->refresh();
    }
}
