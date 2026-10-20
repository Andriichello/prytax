<?php

namespace PryTax\Services\Invoices;

use Carbon\CarbonImmutable;
use Core\Exceptions\RecordLockedException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PryTax\Enums\InvoiceSource;
use PryTax\Enums\ItemUnit;
use PryTax\Helpers\Decimal;
use PryTax\Models\Account;
use PryTax\Models\ChargeType;
use PryTax\Models\Client;
use PryTax\Models\Contract;
use PryTax\Models\Invoice;
use PryTax\Models\Profile;
use PryTax\Models\Project;
use PryTax\Services\Alerts\AlertSync;

/**
 * Class InvoiceWriter.
 *
 * Creates and edits draft invoices with their items (design.md §4, accounting.md). The client's contract gives the
 * currency, the payment terms and the items' description and rate; the receiving account defaults to the one of the
 * client's last invoice; `issued_at` defaults to the end of the period. A generated invoice's total is the sum of its
 * items, `ROUND_HALF_UP(quantity × unit_price, 2)` each, and its adjustment; an external one's is entered, and its
 * optional items describe it without changing it. The contract's rate prices an hour of an invoice in the contract's
 * currency only.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Services\Invoices
 */
class InvoiceWriter
{
    /**
     * Fields of an invoice, which the writer saves.
     *
     * @var array<int, string>
     */
    public const array FIELDS = [
        'client_id', 'contract_id', 'receiving_account_id', 'number', 'source', 'period_start', 'period_end',
        'issued_at', 'due_at', 'currency', 'adjustment_amount', 'adjustment_label', 'notes',
    ];

    /**
     * InvoiceWriter constructor.
     *
     * @param AlertSync $alerts
     */
    public function __construct(
        protected AlertSync $alerts
    ) {}

    /**
     * Create a draft invoice of the validated data.
     *
     * @param Profile $profile
     * @param array $data
     *
     * @return Invoice
     * @throws ValidationException
     */
    public function create(Profile $profile, array $data): Invoice
    {
        $invoice = new Invoice;
        $invoice->profile_id = $profile->getKey();

        return $this->save($profile, $invoice, $this->withDefaults($data));
    }

    /**
     * Update the draft invoice with the validated data; items, when given, replace its own.
     *
     * @param Profile $profile
     * @param Invoice $invoice
     * @param array $data
     *
     * @return Invoice
     * @throws ValidationException
     */
    public function update(Profile $profile, Invoice $invoice, array $data): Invoice
    {
        // A frozen invoice changes its notes only; its status changes have operations of their own
        if ($invoice->isLocked()) {
            $locked = array_values(array_diff(array_keys($data), ['notes']));

            if (!empty($locked)) {
                throw RecordLockedException::changing($invoice, $locked);
            }

            $invoice->update(Arr::only($data, ['notes']));

            return $invoice->refresh()->load('items');
        }

        $current = [
            ...Arr::only($invoice->attributesToArray(), self::FIELDS),
            'source' => $invoice->source->value,
            'total' => $invoice->total,
        ];

        // A typed number is cleared with null; a generated one is the generator's
        if (array_key_exists('number', $data) && $data['number'] === null && $invoice->sequence_number !== null) {
            unset($data['number']);
        }

        if (array_key_exists('issued_at', $data) && !array_key_exists('due_at', $data) && $invoice->due_at !== null) {
            $data['due_at'] = $this->dueAt($data, $invoice->contract_id);
        }

        return $this->save($profile, $invoice, [...$current, ...$data], array_key_exists('items', $data));
    }

    /**
     * Fill the defaults of a new invoice from its client, contract and the client's last invoice.
     *
     * @param array $data
     *
     * @return array
     */
    public function withDefaults(array $data): array
    {
        $client = Client::query()->find($data['client_id'] ?? null);
        $contract = Contract::query()->find($data['contract_id'] ?? null);

        $data['source'] ??= InvoiceSource::Generated->value;
        $data['currency'] = strtoupper((string) ($data['currency'] ?? $contract?->currency ?? $client?->currency ?? 'USD'));
        $data['issued_at'] ??= $data['period_end'] ?? null;

        if (!array_key_exists('due_at', $data)) {
            $data['due_at'] = $this->dueAt($data, $contract?->getKey());
        }

        $data['receiving_account_id'] ??= $this->defaultAccountId($client, $data['currency']);

        return $data;
    }

    /**
     * Get what the form of a new invoice starts with, before it is saved: the defaults of its client and contract
     * (currency, dates, receiving account), the items' description expanded from the contract's template, and the
     * contract's hourly rates by the end of the period, the default one and one per charge type of the client.
     *
     * @param array{client_id: int, contract_id?: int|null, period_start?: string|null, period_end?: string|null, issued_at?: string|null, currency?: string|null} $data
     *
     * @return array<string, mixed>
     */
    public function formDefaults(array $data): array
    {
        $defaults = $this->withDefaults(array_filter($data, fn ($value) => $value !== null));
        $contract = Contract::query()->find($data['contract_id'] ?? null);
        $periodEnd = empty($data['period_end']) ? null : CarbonImmutable::parse($data['period_end']);
        $rated = $contract !== null && $periodEnd !== null && $contract->currency === $defaults['currency'];

        $chargeTypes = ChargeType::query()->where('client_id', $data['client_id'])->where('is_active', true)->get(['id', 'name']);

        return [
            'source' => $defaults['source'],
            'currency' => $defaults['currency'],
            'issued_at' => $defaults['issued_at'],
            'due_at' => $defaults['due_at'],
            'receiving_account_id' => $defaults['receiving_account_id'],
            'description' => $contract?->describe(),
            'hourly_rate' => $rated ? $contract->rateFor($periodEnd) : null,
            'charge_type_rates' => $chargeTypes->map(fn (ChargeType $chargeType) => [
                'charge_type_id' => $chargeType->getKey(),
                'name' => $chargeType->name,
                'hourly_rate' => $rated ? $contract->rateFor($periodEnd, $chargeType->getKey()) : null,
            ])->values()->all(),
        ];
    }

    /**
     * Get the default due date: the issue date plus the contract's payment terms, or null.
     *
     * @param array $data
     * @param int|null $contractId
     *
     * @return string|null
     */
    protected function dueAt(array $data, ?int $contractId): ?string
    {
        $days = Contract::query()->find($contractId)?->payment_terms_days;

        if ($days === null || empty($data['issued_at'])) {
            return null;
        }

        return CarbonImmutable::parse($data['issued_at'])->addDays($days)->toDateString();
    }

    /**
     * Get the default receiving account: the one of the client's last invoice, when it is active and in the
     * currency, or else the first active account in the currency.
     *
     * @param Client|null $client
     * @param string $currency
     *
     * @return int|null
     */
    protected function defaultAccountId(?Client $client, string $currency): ?int
    {
        $last = $client?->invoices()
            ->whereHas('receivingAccount', fn ($query) => $query->where('is_active', true)->where('currency', $currency))
            ->latest('issued_at')
            ->latest('id')
            ->value('receiving_account_id');

        return $last ?? Account::query()
            ->active()
            ->where('currency', $currency)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->value('id');
    }

    /**
     * Check the data's consistency, compute the items and totals, and save the invoice with them.
     *
     * @param Profile $profile
     * @param Invoice $invoice
     * @param array $data
     * @param bool $replaceItems
     *
     * @return Invoice
     * @throws ValidationException
     */
    protected function save(Profile $profile, Invoice $invoice, array $data, bool $replaceItems = true): Invoice
    {
        $client = Client::query()->find($data['client_id']);
        $source = InvoiceSource::from($data['source']);

        if (!$client) {
            throw ValidationException::withMessages(['client_id' => [__('The client is required.')]]);
        }

        if (!empty($data['contract_id']) && !Contract::query()->where('client_id', $client->getKey())->whereKey($data['contract_id'])->exists()) {
            throw ValidationException::withMessages(['contract_id' => [__('The contract isn\'t one of the client\'s.')]]);
        }

        $account = Account::query()->find($data['receiving_account_id'] ?? null);

        if (!$account) {
            throw ValidationException::withMessages(['receiving_account_id' => [__('The receiving account is required.')]]);
        }

        if ($account->currency !== $data['currency']) {
            throw ValidationException::withMessages([
                'receiving_account_id' => [__('The receiving account must be in :currency.', ['currency' => $data['currency']])],
            ]);
        }

        if (!$account->is_active && $account->getKey() !== $invoice->getOriginal('receiving_account_id')) {
            throw ValidationException::withMessages([
                'receiving_account_id' => [__('The receiving account is inactive.')],
            ]);
        }

        if (CarbonImmutable::parse($data['period_end'])->lt(CarbonImmutable::parse($data['period_start']))) {
            throw ValidationException::withMessages(['period_end' => [__('The period can\'t end before it starts.')]]);
        }

        $items = $replaceItems ? $this->items($client, $data) : null;
        $adjustment = Decimal::of($data['adjustment_amount'] ?? '0');

        if ($source === InvoiceSource::External) {
            $total = Decimal::of($data['total'] ?? $invoice->total);
            $subtotal = $total->minus($adjustment);
        } elseif ($items !== null) {
            $subtotal = Decimal::sum(array_column($items, 'amount'));
            $total = $subtotal->plus($adjustment);
        } else {
            $subtotal = Decimal::of($invoice->subtotal);
            $total = $subtotal->plus($adjustment);
        }

        $invoice->fill([
            ...Arr::only($data, self::FIELDS),
            'source' => $source,
            'subtotal' => Decimal::money($subtotal),
            'adjustment_amount' => Decimal::money($adjustment),
            'total' => Decimal::money($total),
        ]);

        // A typed number bypasses the generator
        if (array_key_exists('number', $data) && $data['number'] !== null && $data['number'] !== $invoice->getOriginal('number')) {
            $invoice->forceFill(['sequence_scope_key' => null, 'sequence_number' => null]);
        }

        try {
            DB::transaction(function () use ($invoice, $items) {
                $invoice->save();

                if ($items !== null) {
                    $invoice->items()->get()->each->delete();
                    $invoice->items()->createMany($items);
                }
            });
        } catch (QueryException $exception) {
            if (str_contains($exception->getMessage(), 'invoices_profile_id_number_unique')) {
                throw ValidationException::withMessages(['number' => [__('Another invoice has this number already.')]]);
            }

            throw $exception;
        }

        $this->alerts->syncAfterCommit($profile);

        return $invoice->refresh()->load('items');
    }

    /**
     * Get the attributes of the invoice's items: the project's code and the charge type's name copied, the
     * description from the contract's template and the price from its rate by the end of the period, when not
     * given, and the amount.
     *
     * @param Client $client
     * @param array $data
     *
     * @return array<int, array<string, mixed>>
     * @throws ValidationException
     */
    protected function items(Client $client, array $data): array
    {
        $contract = Contract::query()->find($data['contract_id'] ?? null);
        $periodEnd = CarbonImmutable::parse($data['period_end']);
        $items = [];

        foreach (array_values($data['items'] ?? []) as $index => $item) {
            $project = isset($item['project_id'])
                ? Project::query()->where('client_id', $client->getKey())->find($item['project_id'])
                : null;
            $chargeType = isset($item['charge_type_id'])
                ? ChargeType::query()->where('client_id', $client->getKey())->find($item['charge_type_id'])
                : null;

            if (isset($item['project_id']) && !$project) {
                throw ValidationException::withMessages(["items.$index.project_id" => [__('The project isn\'t one of the client\'s.')]]);
            }

            if (isset($item['charge_type_id']) && !$chargeType) {
                throw ValidationException::withMessages(["items.$index.charge_type_id" => [__('The charge type isn\'t one of the client\'s.')]]);
            }

            $unit = $item['unit'] ?? ItemUnit::Hour->value;
            $rated = $contract !== null && $contract->currency === $data['currency'] && $unit === ItemUnit::Hour->value;
            $price = $item['unit_price'] ?? ($rated ? $contract->rateFor($periodEnd, $chargeType?->getKey()) : null);

            if ($price === null) {
                throw ValidationException::withMessages(["items.$index.unit_price" => [__('The price is required: the contract has no hourly rate in the invoice\'s currency.')]]);
            }

            $description = $item['description'] ?? $contract?->describe();

            if (blank($description)) {
                throw ValidationException::withMessages(["items.$index.description" => [__('The description is required.')]]);
            }

            $quantity = Decimal::of($item['quantity'] ?? '1');

            $items[] = [
                'position' => $index + 1,
                'project_id' => $project?->getKey(),
                'project_code' => $project?->code,
                'charge_type_id' => $chargeType?->getKey(),
                'charge_type' => $chargeType?->name,
                'description' => $description,
                'unit' => $unit,
                'quantity' => Decimal::money($quantity),
                'unit_price' => Decimal::money($price),
                'amount' => Decimal::money(Decimal::times($quantity, $price)),
            ];
        }

        return $items;
    }
}
