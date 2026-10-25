<?php

namespace PryTax\Services\Invoices;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PryTax\Enums\InvoiceStatus;
use PryTax\Helpers\Decimal;
use PryTax\Models\Invoice;
use PryTax\Models\Payment;
use PryTax\Models\Profile;
use PryTax\Services\Alerts\AlertSync;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Class InvoiceLifecycle.
 *
 * The status changes of a frozen invoice past its freeze (design.md §4): sending it, marking it paid once its
 * receipts cover the total (with the date of the last receipt by default), returning a paid one, which its receipts
 * no longer cover, to unpaid, and cancelling it, whose number is never reused. The model's transitions refuse the
 * others with 409.
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
        $invoice = $this->change($invoice, [InvoiceStatus::Ready], fn (Invoice $invoice) => $invoice->forceFill([
            'status' => InvoiceStatus::Sent,
            'sent_at' => $sentAt ?? CarbonImmutable::today()->toDateString(),
        ])->save());

        $this->alerts->syncAfterCommit($profile);

        return $invoice;
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
        $invoice = $this->change($invoice, [InvoiceStatus::Ready, InvoiceStatus::Sent], function (Invoice $invoice) use ($paidAt) {
            $paid = $invoice->receivedAmount();

            if ($paid->isLessThan(Decimal::of($invoice->total))) {
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
        });

        $this->alerts->syncAfterCommit($profile);

        return $invoice;
    }

    /**
     * Return the paid invoice, which its receipts no longer cover, to sent, or to ready when it was paid without being
     * sent, after one of its receipts was deleted, reduced, moved to another invoice or refunded. It runs inside that
     * change's transaction, so the invoice's row stays locked until the change is saved.
     *
     * @param int|null $invoiceId
     *
     * @return void
     */
    public function reopenIfUncovered(?int $invoiceId): void
    {
        if ($invoiceId === null) {
            return;
        }

        $invoice = Invoice::query()->lockForUpdate()->find($invoiceId);

        // A virtual invoice follows its receipt, so it is never left uncovered
        if ($invoice?->status !== InvoiceStatus::Paid || $invoice->isVirtual()
            || $invoice->receivedAmount()->isGreaterThanOrEqualTo(Decimal::of($invoice->total))) {
            return;
        }

        $invoice->forceFill([
            'status' => $invoice->sent_at !== null ? InvoiceStatus::Sent : InvoiceStatus::Ready,
            'paid_at' => null,
        ])->save();
    }

    /**
     * Cancel the invoice on the date (today by default), unless money of its receipts is still kept: receipts are
     * moved to the invoice, which replaces it, or refunded, first.
     *
     * @param Profile $profile
     * @param Invoice $invoice
     * @param string|null $cancelledAt
     *
     * @return Invoice
     */
    public function cancel(Profile $profile, Invoice $invoice, ?string $cancelledAt = null): Invoice
    {
        $invoice = $this->change($invoice, [InvoiceStatus::Ready, InvoiceStatus::Sent], function (Invoice $invoice) use ($cancelledAt) {
            $kept = $invoice->receivedAmount();

            if ($kept->isPositive()) {
                throw new ConflictHttpException(__('The invoice has :amount :currency of receipts: move them to the invoice, which replaces it, or refund them, before cancelling it.', [
                    'amount' => Decimal::money($kept),
                    'currency' => $invoice->currency,
                ]));
            }

            $invoice->forceFill([
                'status' => InvoiceStatus::Cancelled,
                'cancelled_at' => $cancelledAt ?? CarbonImmutable::today()->toDateString(),
            ])->save();
        });

        $this->alerts->syncAfterCommit($profile);

        return $invoice;
    }

    /**
     * Change the invoice in a transaction, which locks its row and makes sure it still has one of the statuses, so
     * two concurrent changes of its status can't both pass.
     *
     * @param Invoice $invoice
     * @param array<int, InvoiceStatus> $statuses
     * @param Closure(Invoice): mixed $change
     *
     * @return Invoice
     * @throws ConflictHttpException
     */
    protected function change(Invoice $invoice, array $statuses, Closure $change): Invoice
    {
        return DB::transaction(function () use ($invoice, $statuses, $change) {
            $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->getKey());

            if (!in_array($locked->status, $statuses, true)) {
                throw new ConflictHttpException(__('The invoice is :status already.', ['status' => $locked->status->value]));
            }

            $change($locked);

            return $locked->refresh();
        });
    }
}
