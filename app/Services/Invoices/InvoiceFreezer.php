<?php

namespace PryTax\Services\Invoices;

use Core\Models\File;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PryTax\Enums\InvoiceStatus;
use PryTax\Enums\SettingSource;
use PryTax\Models\Invoice;
use PryTax\Models\Payment;
use PryTax\Models\Profile;
use PryTax\Services\Alerts\AlertSync;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Class InvoiceFreezer.
 *
 * Freezes a draft into a ready invoice and back (design.md §4). The freeze allocates or rebuilds the number, takes
 * the snapshot of the profile, client, contract, account (with its requisites), items and totals, in the invoice
 * money format in force, with the document settings resolved (design.md §4.1): the language, in which the parties'
 * names are taken, the payment purpose rendered with the final number, the act note and the contract section. It
 * locks the invoice; once that is committed, it renders the PDF from the snapshot, unless an uploaded PDF is the
 * primary document. A payment purpose, which needs a value of the contract the invoice lacks, stops the freeze.
 * Unfreezing, allowed while there are no receipts, deletes the generated files and the snapshot, and keeps the
 * number.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Services\Invoices
 */
class InvoiceFreezer
{
    /**
     * InvoiceFreezer constructor.
     *
     * @param InvoiceNumberGenerator $numbers
     * @param InvoicePdfRenderer $renderer
     * @param AlertSync $alerts
     * @param InvoiceSettingsResolver $settings
     * @param PaymentPurposeRenderer $purposes
     */
    public function __construct(
        protected InvoiceNumberGenerator $numbers,
        protected InvoicePdfRenderer $renderer,
        protected AlertSync $alerts,
        protected InvoiceSettingsResolver $settings,
        protected PaymentPurposeRenderer $purposes
    ) {}

    /**
     * Freeze the draft invoice.
     *
     * @param Profile $profile
     * @param Invoice $invoice
     *
     * @return Invoice
     * @throws ValidationException
     */
    public function freeze(Profile $profile, Invoice $invoice): Invoice
    {
        if ($invoice->status !== InvoiceStatus::Draft) {
            throw new ConflictHttpException(__('Only a draft can be frozen.'));
        }

        $invoice->loadMissing(['items', 'receivingAccount', 'primaryFile']);

        if ($invoice->isGenerated() && $invoice->items->isEmpty()) {
            throw ValidationException::withMessages(['items' => [__('A generated invoice needs at least one item.')]]);
        }

        if (!$invoice->isGenerated() && !$this->hasUploadedPrimary($invoice)) {
            throw ValidationException::withMessages(['file' => [__('An external invoice needs its uploaded PDF as the primary document.')]]);
        }

        if (!$invoice->isGenerated() && blank($invoice->number)) {
            throw ValidationException::withMessages(['number' => [__('An external invoice keeps its own number: type it.')]]);
        }

        if (!$invoice->receivingAccount?->is_active) {
            throw ValidationException::withMessages(['receiving_account_id' => [__('The receiving account is inactive.')]]);
        }

        if (!$invoice->issued_at) {
            throw ValidationException::withMessages(['issued_at' => [__('The issue date is required.')]]);
        }

        $this->assertPurposeComplete($invoice);

        try {
            DB::transaction(function () use ($profile, $invoice) {
                // Locked until frozen, so a second tap waits and finds it frozen, rather than numbering it again
                $this->assertStatus($invoice, InvoiceStatus::Draft, __('Only a draft can be frozen.'));
                $this->numbers->assign($profile, $invoice);

                $invoice->forceFill([
                    'status' => InvoiceStatus::Ready,
                    'locked_at' => now(),
                    'snapshot' => $this->snapshot($profile, $invoice),
                ])->save();
            }, 3);
        } catch (QueryException $exception) {
            if (str_contains($exception->getMessage(), 'invoices_profile_id_number_unique')) {
                throw ValidationException::withMessages([
                    'number' => [__('Another invoice has the number :number already.', ['number' => $invoice->number])],
                ]);
            }

            throw $exception;
        }

        if (!$this->hasUploadedPrimary($invoice)) {
            $this->renderer->attachGenerated($invoice->refresh());
        }

        $this->alerts->syncAfterCommit($profile);

        return $invoice->refresh();
    }

    /**
     * Return the ready invoice to its draft, while it has no receipts.
     *
     * @param Profile $profile
     * @param Invoice $invoice
     *
     * @return Invoice
     */
    public function unfreeze(Profile $profile, Invoice $invoice): Invoice
    {
        if ($invoice->status !== InvoiceStatus::Ready) {
            throw new ConflictHttpException(__('Only a ready invoice can return to its draft.'));
        }

        DB::transaction(function () use ($invoice) {
            $this->assertStatus($invoice, InvoiceStatus::Ready, __('Only a ready invoice can return to its draft.'));

            // A receipt locks its invoice as it is recorded, so none can arrive while this one is locked
            if (Payment::query()->where('invoice_id', $invoice->getKey())->exists()) {
                throw new ConflictHttpException(__('The invoice has receipts, so it can\'t return to its draft.'));
            }

            $generated = $invoice->filesIn(Invoice::GENERATED_COLLECTION)->get();
            $primaryIsGenerated = $generated->contains('id', $invoice->primary_file_id);

            $invoice->forceFill([
                'status' => InvoiceStatus::Draft,
                'locked_at' => null,
                'snapshot' => null,
                'primary_file_id' => $primaryIsGenerated ? null : $invoice->primary_file_id,
            ])->save();

            $generated->each(fn (File $file) => $file->delete());
        });

        $this->alerts->syncAfterCommit($profile);

        return $invoice->refresh();
    }

    /**
     * Lock the invoice's row until the transaction ends, and make sure it still has the status.
     *
     * @param Invoice $invoice
     * @param InvoiceStatus $status
     * @param string $message
     *
     * @return void
     * @throws ConflictHttpException
     */
    protected function assertStatus(Invoice $invoice, InvoiceStatus $status, string $message): void
    {
        $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->getKey());

        if ($locked->status !== $status) {
            throw new ConflictHttpException($message);
        }
    }

    /**
     * Make sure the invoice's payment purpose has the values of the contract, which its template uses: a template
     * with `{contract.number}` needs a contract with a number.
     *
     * @param Invoice $invoice
     *
     * @return void
     * @throws ValidationException
     */
    protected function assertPurposeComplete(Invoice $invoice): void
    {
        $settings = $this->settings->forInvoice($invoice);
        $template = $settings['payment_purpose_template'];
        $missing = $this->purposes->missing($template['value'], $this->purposes->values($invoice, $settings['locale']['value']));

        if (empty($missing)) {
            return;
        }

        $placeholder = '{' . $missing[0] . '}';
        $message = $invoice->contract === null
            ? __('The payment purpose uses :placeholder, but the invoice has no contract.', ['placeholder' => $placeholder])
            : __('The payment purpose uses :placeholder, but the invoice\'s contract has no number.', ['placeholder' => $placeholder]);

        $level = match (SettingSource::from($template['source'])) {
            SettingSource::Invoice => __('The template is the invoice\'s own.'),
            SettingSource::Client => __('The template is the client\'s.'),
            default => __('The template is the ФОП\'s.'),
        };

        throw ValidationException::withMessages(['payment_purpose_template' => [$message . ' ' . $level]]);
    }

    /**
     * Build the snapshot of the invoice, from which its PDF is rendered, so later edits of the profile, client,
     * contract or account never change an issued document. The parties' names and addresses are taken in the
     * invoice's language, and its document settings are resolved, so the template never decides on its own.
     *
     * @param Profile $profile
     * @param Invoice $invoice
     *
     * @return array<string, mixed>
     */
    public function snapshot(Profile $profile, Invoice $invoice): array
    {
        $invoice->loadMissing(['client', 'contract', 'receivingAccount', 'items']);
        $client = $invoice->client;
        $contract = $invoice->contract;
        $account = $invoice->receivingAccount;
        $signature = $profile->filesIn($profile::SIGNATURE_COLLECTION)->latest('id')->first();
        $settings = InvoiceSettingsResolver::values($this->settings->forInvoice($invoice));
        $locale = $settings['locale'];

        return [
            'schema_version' => Invoice::SNAPSHOT_SCHEMA_VERSION,
            'money_format' => $profile->setting('invoice.money_format'),
            'locale' => $locale,
            'frozen_at' => now()->toIso8601String(),
            'profile' => [
                'name' => $profile->nameIn($locale),
                'address' => $profile->addressIn($locale),
                'tax_id' => $profile->tax_id,
                'tax_id_kind' => $profile->tax_id_kind->value,
                'registered_at' => $profile->registered_at?->toDateString(),
                'email' => $profile->email,
                'phone' => $profile->phone,
                'signature_file_id' => $signature?->getKey(),
            ],
            'client' => [
                'name' => $client->legalNameIn($locale),
                'country' => $client->country,
                'vat_number' => $client->vat_number,
                'tax_id' => $client->tax_id,
                'address' => $client->address,
                'email' => $client->email,
            ],
            'contract' => $contract ? [
                'name' => $contract->name,
                'number' => $contract->number,
                'subject' => $contract->subject,
                'started_at' => $contract->started_at->toDateString(),
                'ends_at' => $contract->ends_at?->toDateString(),
                'payment_terms_days' => $contract->payment_terms_days,
            ] : null,
            'presentation' => [
                'payment_purpose' => $this->purposes->forInvoice($invoice, $settings),
                'act_note' => $settings['act_note_enabled'] ? $settings['act_note'] : null,
                'show_contract' => (bool) $settings['show_contract'],
            ],
            'account' => [
                'name' => $account->name,
                'kind' => $account->kind->value,
                'currency' => $account->currency,
                'holder_name' => $account->holder_name,
                'bank_name' => $account->bank_name,
                'iban' => $account->iban,
                'swift' => $account->swift,
                'account_number' => $account->account_number,
                'details' => $account->details,
            ],
            'invoice' => [
                'number' => $invoice->number,
                'source' => $invoice->source->value,
                'period_start' => $invoice->period_start->toDateString(),
                'period_end' => $invoice->period_end->toDateString(),
                'issued_at' => $invoice->issued_at->toDateString(),
                'due_at' => $invoice->due_at?->toDateString(),
                'currency' => $invoice->currency,
            ],
            'items' => $invoice->items->map(fn ($item) => [
                'position' => $item->position,
                'project_code' => $item->project_code,
                'charge_type' => $item->charge_type,
                'description' => $item->description,
                'unit' => $item->unit->value,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'amount' => $item->amount,
            ])->values()->all(),
            'totals' => [
                'subtotal' => $invoice->subtotal,
                'adjustment_amount' => $invoice->adjustment_amount,
                'adjustment_label' => $invoice->adjustment_label,
                'total' => $invoice->total,
            ],
            'footer_note' => $client->invoice_footer_note ?? $profile->setting('invoice.footer_note'),
        ];
    }

    /**
     * Determine if an uploaded PDF is the invoice's primary document.
     *
     * @param Invoice $invoice
     *
     * @return bool
     */
    protected function hasUploadedPrimary(Invoice $invoice): bool
    {
        return $invoice->primary_file_id !== null
            && $invoice->filesIn(Invoice::UPLOADED_COLLECTION)->whereKey($invoice->primary_file_id)->exists();
    }
}
