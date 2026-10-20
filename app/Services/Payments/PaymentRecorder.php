<?php

namespace PryTax\Services\Payments;

use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PryTax\Enums\AccountKind;
use PryTax\Enums\InvoiceStatus;
use PryTax\Enums\PaymentKind;
use PryTax\Enums\TaxableBase;
use PryTax\Helpers\Decimal;
use PryTax\Models\Account;
use PryTax\Models\Invoice;
use PryTax\Models\Payment;
use PryTax\Models\Profile;
use PryTax\Services\Alerts\AlertSync;
use PryTax\Services\Taxes\PeriodLock;

/**
 * Class PaymentRecorder.
 *
 * Records receipts (design.md §6.1). It asserts that the invoice and the account are the profile's, that the
 * invoice is frozen (ready, sent or paid), that the receipt lands in the account's currency and that its quarter
 * isn't locked, and computes the snapshot. An advance has no invoice until it is linked to one. Editing the amount,
 * the currency or the date recomputes the snapshot, subject to the lock of both the old and the new quarter, and so
 * does any update of a receipt saved at a manual rate, once the NBU's rate of its day is available.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Services\Payments
 */
class PaymentRecorder
{
    /**
     * PaymentRecorder constructor.
     *
     * @param PaymentSnapshotter $snapshotter
     * @param PeriodLock $lock
     * @param AlertSync $alerts
     */
    public function __construct(
        protected PaymentSnapshotter $snapshotter,
        protected PeriodLock $lock,
        protected AlertSync $alerts
    ) {}

    /**
     * Record a receipt of the validated data.
     *
     * @param Profile $profile
     * @param array $data
     *
     * @return Payment
     * @throws ValidationException
     */
    public function record(Profile $profile, array $data): Payment
    {
        $attributes = $this->attributes($profile, $data, null);

        $payment = DB::transaction(function () use ($profile, $attributes, $data) {
            $this->lockInvoice($attributes['invoice_id']);
            $this->lock->assertOpen($profile, CarbonImmutable::parse($attributes['received_at']), $data['correction_note'] ?? null);

            $payment = new Payment;
            $payment->forceFill([...$attributes, 'profile_id' => $profile->getKey()])->save();

            return $payment;
        });

        $this->alerts->syncAfterCommit($profile);

        return $payment->refresh();
    }

    /**
     * Record one wire, which covers several invoices of one currency (design.md §6.1): a receipt of each invoice,
     * all sharing a `receipt_group_id`, with the received amount and the client's fee split in proportion to the
     * invoiced amounts, to the kopeck, so the shares add up to the wire.
     *
     * @param Profile $profile
     * @param array{receipts: array<int, array{invoice_id: int, amount: string}>, received_to_account_id: int, received_at: string, received_amount?: string|null, client_fee_amount?: string|null, nbu_rate?: string|null, correction_note?: string|null} $data
     *
     * @return Collection<int, Payment>
     * @throws ValidationException
     */
    public function recordGroup(Profile $profile, array $data): Collection
    {
        $receipts = array_values($data['receipts']);
        $invoices = Invoice::query()->whereKey(array_column($receipts, 'invoice_id'))->get()->keyBy('id');
        $currencies = $invoices->pluck('currency')->unique();

        if ($currencies->count() !== 1) {
            throw ValidationException::withMessages([
                'receipts' => [__('One wire covers invoices of one currency.')],
            ]);
        }

        $account = Account::query()->find($data['received_to_account_id']);
        $amounts = array_column($receipts, 'amount');
        $received = $data['received_amount'] ?? null;

        if (($received === null || $received === '') && $account?->currency === $currencies->first()) {
            $received = Decimal::money(Decimal::sum($amounts));
        }

        if ($received === null || $received === '') {
            throw ValidationException::withMessages([
                'received_amount' => [__('The received amount is required, when the account\'s currency differs.')],
            ]);
        }

        $shares = Decimal::split($received, $amounts);
        $fees = empty($data['client_fee_amount']) ? [] : Decimal::split($data['client_fee_amount'], $amounts);
        $group = (string) Str::uuid();

        $rows = array_map(fn (int $index) => $this->attributes($profile, [
            'kind' => PaymentKind::Invoice->value,
            'invoice_id' => $receipts[$index]['invoice_id'],
            'amount' => $receipts[$index]['amount'],
            'client_fee_amount' => $fees[$index] ?? null,
            'received_to_account_id' => $data['received_to_account_id'],
            'received_amount' => $shares[$index],
            'received_at' => $data['received_at'],
            'nbu_rate' => $data['nbu_rate'] ?? null,
            'receipt_group_id' => $group,
        ], null), array_keys($receipts));

        $payments = DB::transaction(function () use ($profile, $rows, $data) {
            $this->lock->assertOpen($profile, CarbonImmutable::parse($data['received_at']), $data['correction_note'] ?? null);

            return collect($rows)->map(function (array $attributes) use ($profile) {
                $this->lockInvoice($attributes['invoice_id']);

                $payment = new Payment;
                $payment->forceFill([...$attributes, 'profile_id' => $profile->getKey()])->save();

                return $payment;
            });
        });

        $this->alerts->syncAfterCommit($profile);

        return $payments->map(fn (Payment $payment) => $payment->refresh());
    }

    /**
     * Compute the attributes and the snapshot of a receipt, without saving it, for the form's preview.
     *
     * @param Profile $profile
     * @param array $data
     *
     * @return array<string, mixed>
     * @throws ValidationException
     */
    public function preview(Profile $profile, array $data): array
    {
        $attributes = $this->attributes($profile, $data, null);
        $date = CarbonImmutable::parse($attributes['received_at']);

        return [
            ...$attributes,
            'period_year' => $date->year,
            'period_quarter' => $date->quarter,
            'period_month' => $date->month,
            'receipt_fee' => $attributes['received_currency'] === $attributes['currency']
                ? Decimal::money(Decimal::of($attributes['amount'])->minus(Decimal::of($attributes['received_amount'])))
                : null,
            'invoice' => $this->coverage(isset($attributes['invoice_id']) ? Invoice::query()->find($attributes['invoice_id']) : null, $attributes['amount']),
            // The declared quarter, which saving would correct, so the form asks for a correction note first
            'locked_quarter' => ($locked = $this->lock->lockedBy($profile, $date)) ? ['year' => $locked->year, 'quarter' => $locked->quarter] : null,
        ];
    }

    /**
     * Update the receipt with the validated data.
     *
     * @param Profile $profile
     * @param Payment $payment
     * @param array $data
     *
     * @return Payment
     * @throws ValidationException
     */
    public function update(Profile $profile, Payment $payment, array $data): Payment
    {
        if ($payment->kind === PaymentKind::FxGain) {
            throw ValidationException::withMessages([
                'kind' => [__('An FX gain follows its transfer, so it is changed through the transfer.')],
            ]);
        }

        // Linking an advance to an invoice makes it a receipt of the invoice
        if ($payment->kind === PaymentKind::Advance && !empty($data['invoice_id'])) {
            $data['kind'] = PaymentKind::Invoice->value;
        }

        $merged = [...$payment->only([
            'kind', 'invoice_id', 'description', 'currency', 'amount', 'client_fee_amount',
            'received_to_account_id', 'received_amount', 'receipt_group_id',
        ]), 'received_at' => $payment->received_at->toDateString(), ...$data];

        $merged['kind'] = $merged['kind'] instanceof PaymentKind ? $merged['kind']->value : $merged['kind'];
        $invoice = $merged['kind'] === PaymentKind::Invoice->value ? Invoice::query()->find($merged['invoice_id'] ?? null) : null;

        // The taxable event happened in the receipt's currency, which a link to an invoice can't change
        if (($invoice?->currency ?? $payment->currency) !== $payment->currency) {
            throw ValidationException::withMessages([
                'invoice_id' => [__('The receipt is in :currency, so it belongs to an invoice in :currency only.', [
                    'currency' => $payment->currency,
                ])],
            ]);
        }

        $attributes = $this->attributes($profile, $merged, $payment);

        $changesIncome = $this->changesIncome($profile, $payment, $attributes);

        if (!$changesIncome) {
            $attributes = Arr::except($attributes, [
                'nbu_rate', 'nbu_rate_date', 'exchange_rate_id', 'rate_is_manual', 'uah_amount', 'tax_rule_id',
                'single_tax_uah', 'military_levy_uah',
            ]);
        }

        DB::transaction(function () use ($profile, $payment, $attributes, $changesIncome, $data) {
            Payment::query()->lockForUpdate()->find($payment->getKey());

            if ($attributes['invoice_id'] !== $payment->invoice_id) {
                $this->lockInvoice($attributes['invoice_id']);
            }

            $moved = (int) $attributes['received_to_account_id'] !== $payment->received_to_account_id
                || !Decimal::of($attributes['received_amount'])->isEqualTo(Decimal::of($payment->received_amount));

            if ($moved && ($payment->allocations()->exists() || $payment->refunds()->exists())) {
                throw ValidationException::withMessages([
                    'received_amount' => [__('The receipt was transferred on or refunded, so its account and received amount can\'t change.')],
                ]);
            }

            $refunded = Decimal::of((string) $payment->refunds()->sum('amount'));

            if ($refunded->isGreaterThan(Decimal::of($attributes['amount']))) {
                throw ValidationException::withMessages([
                    'amount' => [__('The receipt was refunded :refunded :currency, so its amount can\'t be less.', [
                        'refunded' => Decimal::money($refunded),
                        'currency' => $payment->currency,
                    ])],
                ]);
            }

            if ($changesIncome) {
                $this->lock->assertOpen($profile, [
                    $payment->received_at,
                    CarbonImmutable::parse($attributes['received_at']),
                ], $data['correction_note'] ?? null);
            }

            $payment->forceFill($attributes)->save();
        });

        $this->alerts->syncAfterCommit($profile);

        return $payment->refresh();
    }

    /**
     * Whether the update changes the receipt's income, so its snapshot is recomputed: its amount, currency or date,
     * its received amount when the profile taxes what was received, or a manual rate, which the NBU's or another
     * manual one replaces. Anything else keeps the stored snapshot.
     *
     * @param Profile $profile
     * @param Payment $payment
     * @param array<string, mixed> $attributes
     *
     * @return bool
     */
    protected function changesIncome(Profile $profile, Payment $payment, array $attributes): bool
    {
        $fields = ['amount', 'currency', 'received_at'];

        if ($profile->setting('income.taxable_base') === TaxableBase::Received->value) {
            $fields[] = 'received_amount';
        }

        $changed = collect($fields)->contains(
            fn (string $field) => (string) $attributes[$field] !== (string) ($field === 'received_at'
                ? $payment->received_at->toDateString()
                : $payment->getAttribute($field))
        );

        return $changed || $this->replacesManualRate($payment, $attributes);
    }

    /**
     * Delete the receipt, when nothing references it and its quarter is open.
     *
     * @param Profile $profile
     * @param Payment $payment
     * @param string|null $correctionNote
     *
     * @return void
     * @throws ValidationException
     */
    public function delete(Profile $profile, Payment $payment, ?string $correctionNote = null): void
    {
        if ($payment->kind === PaymentKind::FxGain) {
            throw ValidationException::withMessages([
                'kind' => [__('An FX gain follows its transfer, so it is deleted with the transfer.')],
            ]);
        }

        $references = array_filter([
            'transfers' => $payment->allocations()->exists(),
            'refunds' => $payment->refunds()->exists(),
            'tax payments' => $payment->taxPayments()->exists(),
        ]);

        if (!empty($references)) {
            throw ValidationException::withMessages([
                'payment' => [__('The receipt is referenced by :references, so it can\'t be deleted.', [
                    'references' => implode(', ', array_keys($references)),
                ])],
            ]);
        }

        DB::transaction(function () use ($profile, $payment, $correctionNote) {
            $this->lock->assertOpen($profile, $payment->received_at, $correctionNote);

            $payment->delete();
        });

        $this->alerts->syncAfterCommit($profile);
    }

    /**
     * Lock the invoice of a receipt until the transaction ends, and make sure it is still frozen, so a concurrent
     * return of the invoice to its draft can't miss the receipt.
     *
     * @param int|null $invoiceId
     *
     * @return void
     * @throws ValidationException
     */
    protected function lockInvoice(?int $invoiceId): void
    {
        if ($invoiceId === null) {
            return;
        }

        $invoice = Invoice::query()->lockForUpdate()->find($invoiceId);

        if (!$invoice || !in_array($invoice->status, [InvoiceStatus::Ready, InvoiceStatus::Sent, InvoiceStatus::Paid], true)) {
            throw ValidationException::withMessages([
                'invoice_id' => [__('Receipts are recorded for frozen invoices only: ready, sent or paid.')],
            ]);
        }
    }

    /**
     * Whether the update replaces the manual rate of the receipt: by the NBU's, once it is available, or by another
     * manual one.
     *
     * @param Payment $payment
     * @param array<string, mixed> $attributes
     *
     * @return bool
     */
    protected function replacesManualRate(Payment $payment, array $attributes): bool
    {
        return $payment->rate_is_manual
            && (!$attributes['rate_is_manual'] || !Decimal::of($attributes['nbu_rate'])->isEqualTo(Decimal::of($payment->nbu_rate)));
    }

    /**
     * Describe how the receipts of the invoice cover it, with the given amount added: what they cover, what is
     * left, and whether they cover the total, so the form suggests marking it paid, or exceed it.
     *
     * @param Invoice|null $invoice
     * @param string|null $adding An amount to add to the stored receipts, such as the one of a form.
     *
     * @return array{id: int, number: string|null, total: string, paid_amount: string, remaining: string, covers_total: bool, overpaid: bool}|null
     */
    public function coverage(?Invoice $invoice, ?string $adding = null): ?array
    {
        if (!$invoice) {
            return null;
        }

        $paid = $invoice->receivedAmount()->plus(Decimal::of($adding));
        $total = Decimal::of($invoice->total);

        return [
            'id' => $invoice->getKey(),
            'number' => $invoice->number,
            'status' => $invoice->status->value,
            'total' => Decimal::money($total),
            'paid_amount' => Decimal::money($paid),
            'remaining' => Decimal::money(Decimal::max($total->minus($paid), 0)),
            'covers_total' => $paid->isGreaterThanOrEqualTo($total),
            'overpaid' => $paid->isGreaterThan($total),
        ];
    }

    /**
     * Get the attributes of a receipt of the data, asserting its invoice and account, with its snapshot.
     *
     * @param Profile $profile
     * @param array $data
     * @param Payment|null $existing
     *
     * @return array<string, mixed>
     * @throws ValidationException
     */
    protected function attributes(Profile $profile, array $data, ?Payment $existing): array
    {
        $kind = PaymentKind::from($data['kind'] ?? PaymentKind::Invoice->value);
        $invoice = null;

        if ($kind === PaymentKind::FxGain) {
            throw ValidationException::withMessages(['kind' => [__('FX gains are recorded by transfers.')]]);
        }

        if ($kind === PaymentKind::Invoice) {
            $invoice = Invoice::query()->find($data['invoice_id'] ?? null);

            if (!$invoice) {
                throw ValidationException::withMessages(['invoice_id' => [__('The invoice is required.')]]);
            }

            $frozen = [InvoiceStatus::Ready, InvoiceStatus::Sent, InvoiceStatus::Paid];
            // A refunded receipt stays with its cancelled invoice, and may still be corrected there
            $stays = $existing !== null && $existing->invoice_id === $invoice->getKey() && $invoice->status === InvoiceStatus::Cancelled;

            if (!in_array($invoice->status, $frozen, true) && !$stays) {
                throw ValidationException::withMessages([
                    'invoice_id' => [__('Receipts are recorded for frozen invoices only: ready, sent or paid.')],
                ]);
            }
        }

        $account = Account::query()->find($data['received_to_account_id'] ?? null);

        if (!$account) {
            throw ValidationException::withMessages(['received_to_account_id' => [__('The account is required.')]]);
        }

        $accountChanged = $existing === null || $existing->received_to_account_id !== $account->getKey();

        if ($accountChanged && !$account->is_active) {
            throw ValidationException::withMessages([
                'received_to_account_id' => [__('The account is inactive, so receipts can\'t land on it.')],
            ]);
        }

        if ($account->kind === AccountKind::Personal) {
            throw ValidationException::withMessages([
                'received_to_account_id' => [__('A personal account isn\'t a business one, so receipts can\'t land on it.')],
            ]);
        }

        $currency = $invoice?->currency ?? strtoupper((string) ($data['currency'] ?? $account->currency));
        $amount = Decimal::money($data['amount']);
        $receivedAmount = Decimal::money($data['received_amount'] ?? ($account->currency === $currency ? $amount : null));

        if (!Decimal::of($receivedAmount)->isPositive() && $account->currency !== $currency) {
            throw ValidationException::withMessages([
                'received_amount' => [__('The received amount is required, when the account\'s currency differs.')],
            ]);
        }

        $receivedAt = CarbonImmutable::parse($data['received_at']);

        $snapshot = $this->snapshotter->compute(
            $profile,
            $currency,
            $amount,
            $receivedAmount,
            $account->currency,
            $receivedAt,
            $data['nbu_rate'] ?? null,
        );

        return [
            'kind' => $kind,
            'invoice_id' => $invoice?->getKey(),
            'description' => $data['description'] ?? null,
            'receipt_group_id' => $data['receipt_group_id'] ?? null,
            'currency' => $currency,
            'amount' => $amount,
            'client_fee_amount' => Decimal::moneyOrNull($data['client_fee_amount'] ?? null),
            'received_to_account_id' => $account->getKey(),
            'received_amount' => $receivedAmount,
            'received_currency' => $account->currency,
            'received_at' => $receivedAt->toDateString(),
            ...$snapshot,
        ];
    }
}
