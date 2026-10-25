<?php

namespace PryTax\Services\Invoices;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PryTax\Enums\InvoiceSource;
use PryTax\Enums\InvoiceStatus;
use PryTax\Enums\ItemUnit;
use PryTax\Models\Contract;
use PryTax\Models\Invoice;
use PryTax\Models\Payment;
use PryTax\Models\Profile;
use PryTax\Services\Alerts\AlertSync;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Class VirtualInvoices.
 *
 * Virtual invoices (design.md §4.2): the record «Без інвойсу» of a payment, which a client made under the contract with
 * no invoice, kept for the books only. It is made with its receipt, paid from the start, takes the receipt's amount,
 * date and account, and has no number, due date, sending or document. When the client asks for an invoice after all,
 * it is issued as a generated one: numbered, with one item and its PDF, still paid.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Services\Invoices
 */
class VirtualInvoices
{
    /**
     * VirtualInvoices constructor.
     *
     * @param InvoiceNumberGenerator $numbers
     * @param InvoiceFreezer $freezer
     * @param InvoicePdfRenderer $renderer
     * @param AlertSync $alerts
     */
    public function __construct(
        protected InvoiceNumberGenerator $numbers,
        protected InvoiceFreezer $freezer,
        protected InvoicePdfRenderer $renderer,
        protected AlertSync $alerts
    ) {}

    /**
     * Make the virtual invoice of a receipt, unsaved: of the client and the contract, in the contract's currency, for
     * the receipt's amount, dated and received as the receipt.
     *
     * @param Profile $profile
     * @param array{client_id: int, contract_id: int, period_start: string, period_end: string, notes?: string|null} $virtual
     * @param array{amount: string, received_to_account_id: int, received_at: string} $receipt
     *
     * @return Invoice
     */
    public function make(Profile $profile, array $virtual, array $receipt): Invoice
    {
        $contract = Contract::query()->findOrFail($virtual['contract_id']);

        $invoice = new Invoice;
        $invoice->forceFill([
            'profile_id' => $profile->getKey(),
            'client_id' => $virtual['client_id'],
            'contract_id' => $contract->getKey(),
            'source' => InvoiceSource::Virtual,
            'status' => InvoiceStatus::Paid,
            'currency' => $contract->currency,
            'period_start' => $virtual['period_start'],
            'period_end' => $virtual['period_end'],
            'adjustment_amount' => '0.00',
            'notes' => $virtual['notes'] ?? null,
        ]);

        return $this->follow($invoice, $receipt);
    }

    /**
     * Save the virtual invoice of a receipt.
     *
     * @param Profile $profile
     * @param array{client_id: int, contract_id: int, period_start: string, period_end: string, notes?: string|null} $virtual
     * @param array{amount: string, received_to_account_id: int, received_at: string} $receipt
     *
     * @return Invoice
     */
    public function create(Profile $profile, array $virtual, array $receipt): Invoice
    {
        $invoice = $this->make($profile, $virtual, $receipt);
        $invoice->save();

        return $invoice;
    }

    /**
     * Change the client, the contract, the period or the note of a virtual invoice. Its contract stays in the
     * currency of its receipt, since the taxable event happened in it.
     *
     * @param Invoice $invoice
     * @param array{client_id: int, contract_id: int, period_start: string, period_end: string, notes?: string|null} $virtual
     *
     * @return void
     * @throws ValidationException
     */
    public function change(Invoice $invoice, array $virtual): void
    {
        $contract = Contract::query()->findOrFail($virtual['contract_id']);

        if ($contract->currency !== $invoice->currency) {
            throw ValidationException::withMessages([
                'virtual_invoice.contract_id' => [__('The receipt is in :currency, so it belongs to a contract in :currency only.', [
                    'currency' => $invoice->currency,
                ])],
            ]);
        }

        $invoice->forceFill([
            'client_id' => $virtual['client_id'],
            'contract_id' => $contract->getKey(),
            'period_start' => $virtual['period_start'],
            'period_end' => $virtual['period_end'],
            'notes' => array_key_exists('notes', $virtual) ? $virtual['notes'] : $invoice->notes,
        ])->save();
    }

    /**
     * Make the virtual invoice follow its receipt, saved: the amount, the date and the account.
     *
     * @param Invoice $invoice
     * @param Payment $payment
     *
     * @return void
     */
    public function sync(Invoice $invoice, Payment $payment): void
    {
        $this->follow($invoice, [
            'amount' => (string) $payment->amount,
            'received_to_account_id' => $payment->received_to_account_id,
            'received_at' => $payment->received_at->toDateString(),
        ])->save();
    }

    /**
     * Issue the virtual invoice as a generated one, when the client asks for an invoice after all: in one
     * transaction it gets the next number of its counter by the date rule, one fixed item for the whole amount,
     * described by its contract, and its snapshot; its PDF is rendered after. It keeps its issue date (the payment's),
     * its period and its paid status.
     *
     * @param Profile $profile
     * @param Invoice $invoice
     *
     * @return Invoice
     * @throws ValidationException
     */
    public function issue(Profile $profile, Invoice $invoice): Invoice
    {
        if (!$invoice->isVirtual()) {
            throw new ConflictHttpException(__('Only a record without an invoice is issued as an invoice.'));
        }

        DB::transaction(function () use ($profile, $invoice) {
            $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->getKey());

            if (!$locked->isVirtual()) {
                throw new ConflictHttpException(__('Only a record without an invoice is issued as an invoice.'));
            }

            $invoice->loadMissing(['contract', 'client']);
            $contract = $invoice->contract;

            $invoice->items()->create([
                'position' => 1,
                'description' => $contract->describe() ?: ($contract->subject ?? $contract->name),
                'unit' => ItemUnit::Fixed,
                'quantity' => '1.00',
                'unit_price' => $invoice->total,
                'amount' => $invoice->total,
            ]);

            $invoice->source = InvoiceSource::Generated;
            $this->freezer->assertPurposeComplete($invoice);
            $this->numbers->assign($profile, $invoice);

            $invoice->forceFill([
                'locked_at' => now(),
                'snapshot' => $this->freezer->snapshot($profile, $invoice->load('items')),
            ])->save();
        }, 3);

        $this->renderer->attachGenerated($invoice->refresh());
        $this->alerts->syncAfterCommit($profile);

        return $invoice->refresh();
    }

    /**
     * Set what a virtual invoice takes from its receipt: the amount as its total, the date as its issue and payment
     * dates, and the account.
     *
     * @param Invoice $invoice
     * @param array{amount: string, received_to_account_id: int, received_at: string} $receipt
     *
     * @return Invoice
     */
    protected function follow(Invoice $invoice, array $receipt): Invoice
    {
        return $invoice->forceFill([
            'receiving_account_id' => $receipt['received_to_account_id'],
            'subtotal' => $receipt['amount'],
            'total' => $receipt['amount'],
            'issued_at' => $receipt['received_at'],
            'paid_at' => $receipt['received_at'],
        ]);
    }
}
