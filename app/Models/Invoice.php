<?php

namespace PryTax\Models;

use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Core\Models\BaseModel;
use Core\Models\File;
use Core\Models\Interfaces\HasFilesInterface;
use Core\Models\Interfaces\LockableInterface;
use Core\Models\Interfaces\TransitionsStatusInterface;
use Core\Models\Traits\Audited;
use Core\Models\Traits\HasFiles;
use Core\Models\Traits\Lockable;
use Core\Models\Traits\TracksCreator;
use Core\Models\Traits\TransitionsStatus;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use PryTax\Enums\InvoiceLocale;
use PryTax\Enums\InvoiceSource;
use PryTax\Enums\InvoiceStatus;
use PryTax\Helpers\Decimal;
use PryTax\Models\Traits\BelongsToProfile;
use PryTax\Queries\InvoiceQuery;
use PryTax\Services\Invoices\InvoiceNumberGenerator;
use PryTax\Services\Invoices\InvoiceSettingsResolver;
use PryTax\Services\Invoices\PaymentPurposeRenderer;

/**
 * Class Invoice.
 *
 * An invoice of a client (design.md §4): draft → ready → sent → paid, or cancelled from ready or sent. The freeze
 * (draft → ready) allocates the number, takes a snapshot of the profile, client, contract, account and items, and
 * renders the PDF from it; past the draft the invoice is locked, except for its status, its dates of sending, payment
 * and cancellation, its notes and its primary file. A ready invoice without receipts may go back to its draft.
 * External invoices (`source = external`) are uploaded PDFs, whose total is entered by hand. Its document settings
 * (language, payment purpose, act note, contract section, style, the two notes) are null to take the client's (design.md
 * §4.1); they lock with the invoice, and the freeze writes the resolved ones into the snapshot.
 *
 * @property int $id
 * @property int $profile_id
 * @property int $client_id
 * @property int|null $contract_id
 * @property int $receiving_account_id
 * @property int|null $created_by_user_id
 * @property string|null $number
 * @property string|null $sequence_scope_key
 * @property int|null $sequence_number
 * @property InvoiceSource $source
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property Carbon $issued_at
 * @property Carbon|null $due_at
 * @property string $currency
 * @property InvoiceLocale|null $locale
 * @property string|null $payment_purpose_template
 * @property bool|null $act_note_enabled
 * @property string|null $act_note
 * @property bool|null $show_contract
 * @property string|null $style A key of InvoiceStyle; a removed style's key stays as stored.
 * @property bool|null $payment_note_enabled Whether the invoice has a payment note of its own (true) or none (false).
 * @property string|null $payment_note
 * @property bool|null $other_note_enabled Whether the invoice has another note of its own (true) or none (false).
 * @property string|null $other_note
 * @property string $subtotal
 * @property string $adjustment_amount
 * @property string|null $adjustment_label
 * @property string $total
 * @property InvoiceStatus $status
 * @property Carbon|null $locked_at
 * @property Carbon|null $sent_at
 * @property Carbon|null $paid_at
 * @property Carbon|null $cancelled_at
 * @property array|null $snapshot
 * @property string|null $notes
 * @property int|null $primary_file_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 *
 * @property-read string $paid_amount
 * @property-read string $payment_purpose_preview
 *
 * @property Client $client
 * @property Contract|null $contract
 * @property Account $receivingAccount
 * @property File|null $primaryFile
 *
 * @method static InvoiceQuery query()
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Models
 */
#[UseFactory(InvoiceFactory::class)]
class Invoice extends BaseModel implements HasFilesInterface, LockableInterface, TransitionsStatusInterface
{
    use Audited;
    use BelongsToProfile;

    /** @use HasFactory<InvoiceFactory> */
    use HasFactory;

    use HasFiles;
    use Lockable;
    use SoftDeletes;
    use TracksCreator;
    use TransitionsStatus;

    /**
     * Collection of the PDFs, which PryTax renders.
     *
     * @var string
     */
    public const string GENERATED_COLLECTION = 'generated';

    /**
     * Collection of the PDFs, which users upload.
     *
     * @var string
     */
    public const string UPLOADED_COLLECTION = 'uploaded';

    /**
     * Version of the snapshot's structure, which the PDF template reads: 2 adds the language, the parties' names in
     * it and the resolved document settings (`presentation`, design.md §4.1); 3 adds the style and the payment and
     * the other note to them, and drops the footer note, which the notes replace.
     *
     * @var int
     */
    public const int SNAPSHOT_SCHEMA_VERSION = 3;

    /**
     * The attributes that are mass-assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'client_id',
        'contract_id',
        'receiving_account_id',
        'number',
        'source',
        'period_start',
        'period_end',
        'issued_at',
        'due_at',
        'currency',
        'locale',
        'payment_purpose_template',
        'act_note_enabled',
        'act_note',
        'show_contract',
        'style',
        'payment_note_enabled',
        'payment_note',
        'other_note_enabled',
        'other_note',
        'subtotal',
        'adjustment_amount',
        'adjustment_label',
        'total',
        'status',
        'sent_at',
        'paid_at',
        'cancelled_at',
        'notes',
    ];

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'source' => 'generated',
        'status' => 'draft',
        'subtotal' => '0.00',
        'adjustment_amount' => '0.00',
        'total' => '0.00',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'paid_amount',
    ];

    /**
     * The attributes that should be hidden for serialization: the snapshot copies the profile's tax id and the
     * account's bank details, so a list doesn't carry it, unless asked to (`?append=snapshot`); `active_number` is
     * the number, which a deleted draft frees, kept unique by the database.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'snapshot',
        'active_number',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'client_id' => 'int',
        'contract_id' => 'int',
        'receiving_account_id' => 'int',
        'sequence_number' => 'int',
        'source' => InvoiceSource::class,
        'period_start' => 'date:Y-m-d',
        'period_end' => 'date:Y-m-d',
        'issued_at' => 'date:Y-m-d',
        'due_at' => 'date:Y-m-d',
        'locale' => InvoiceLocale::class,
        'act_note_enabled' => 'boolean',
        'show_contract' => 'boolean',
        'payment_note_enabled' => 'boolean',
        'other_note_enabled' => 'boolean',
        'subtotal' => 'decimal:2',
        'adjustment_amount' => 'decimal:2',
        'total' => 'decimal:2',
        'status' => InvoiceStatus::class,
        'locked_at' => 'datetime',
        'sent_at' => 'date:Y-m-d',
        'paid_at' => 'date:Y-m-d',
        'cancelled_at' => 'date:Y-m-d',
        'snapshot' => 'encrypted:array',
        'primary_file_id' => 'int',
    ];

    /**
     * Get the attributes, which the audit log leaves out: the snapshot, which copies the profile's tax id and the
     * account's bank details, and is rebuilt from the audited records on every freeze.
     *
     * @return array<int, string>
     */
    public function auditExcluded(): array
    {
        return ['snapshot', 'active_number'];
    }

    /**
     * Determine if the invoice is locked: past its draft.
     *
     * @return bool
     */
    public function isLocked(): bool
    {
        $status = $this->getAttribute('status');

        return ($status instanceof InvoiceStatus ? $status : InvoiceStatus::from($status)) !== InvoiceStatus::Draft;
    }

    /**
     * Attributes, which may change while the invoice is locked.
     *
     * @return array<int, string>
     */
    public function unlockedAttributes(): array
    {
        return ['status', 'sent_at', 'paid_at', 'cancelled_at', 'notes', 'primary_file_id'];
    }

    /**
     * Statuses, to which each status may change (design.md §4). A ready invoice may be paid without being marked as
     * sent; a paid one returns to sent, or to ready when it was never sent, only when its receipts no longer cover it
     * (InvoiceLifecycle::reopenIfUncovered()); a cancelled one never changes again.
     *
     * @return array<string, array<int, InvoiceStatus>>
     */
    public function statusTransitions(): array
    {
        return [
            InvoiceStatus::Draft->value => [InvoiceStatus::Ready],
            InvoiceStatus::Ready->value => [
                InvoiceStatus::Draft,
                InvoiceStatus::Sent,
                InvoiceStatus::Paid,
                InvoiceStatus::Cancelled,
            ],
            InvoiceStatus::Sent->value => [InvoiceStatus::Paid, InvoiceStatus::Cancelled],
            InvoiceStatus::Paid->value => [InvoiceStatus::Sent, InvoiceStatus::Ready],
        ];
    }

    /**
     * Client of the invoice.
     *
     * @return BelongsTo
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Contract of the invoice.
     *
     * @return BelongsTo
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /**
     * Account, to which the client pays.
     *
     * @return BelongsTo
     */
    public function receivingAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'receiving_account_id');
    }

    /**
     * Line items of the invoice, by their position.
     *
     * @return HasMany
     */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('position');
    }

    /**
     * Receipts of the invoice, by their date.
     *
     * @return HasMany
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('received_at')->orderBy('id');
    }

    /**
     * The primary document of the invoice: its generated PDF, or an uploaded one, which replaces it.
     *
     * @return BelongsTo
     */
    public function primaryFile(): BelongsTo
    {
        return $this->belongsTo(File::class, 'primary_file_id');
    }

    /**
     * Determine if the invoice is generated by PryTax, rather than uploaded.
     *
     * @return bool
     */
    public function isGenerated(): bool
    {
        return $this->source === InvoiceSource::Generated;
    }

    /**
     * Get what the receipts of the invoice cover, net of refunds, as it is stored now.
     *
     * @return BigDecimal
     */
    public function receivedAmount(): BigDecimal
    {
        $received = Payment::query()->where('invoice_id', $this->getKey())->sum('amount');
        $refunded = Refund::query()
            ->whereHas('payment', fn ($query) => $query->where('invoice_id', $this->getKey()))
            ->sum('amount');

        return Decimal::of((string) $received)->minus(Decimal::of((string) $refunded));
    }

    /**
     * What the receipts of the invoice cover, net of refunds: the selected value (InvoiceQuery::withPaidAmount()),
     * or else the stored one.
     *
     * @return Attribute
     */
    protected function paidAmount(): Attribute
    {
        return Attribute::get(fn ($value) => Decimal::money($value ?? $this->receivedAmount()));
    }

    /**
     * The number a draft would get, if it were frozen now, or null past the draft.
     *
     * @return Attribute
     */
    protected function numberPreview(): Attribute
    {
        return Attribute::get(fn () => $this->status === InvoiceStatus::Draft
            ? app(InvoiceNumberGenerator::class)->preview($this->profile, $this)
            : null);
    }

    /**
     * The document settings of the invoice (design.md §4.1), each with the level it comes from: `{locale: {value: 'en',
     * source: 'client'}, …}`.
     *
     * @return Attribute
     */
    protected function effectiveSettings(): Attribute
    {
        return Attribute::get(fn () => app(InvoiceSettingsResolver::class)->forInvoice($this));
    }

    /**
     * The payment purpose of the invoice: the frozen one, or else the one its settings render now.
     *
     * @return Attribute
     */
    protected function paymentPurposePreview(): Attribute
    {
        return Attribute::get(function () {
            $frozen = data_get($this->snapshot, 'presentation.payment_purpose');

            if ($frozen !== null) {
                return $frozen;
            }

            $settings = InvoiceSettingsResolver::values(app(InvoiceSettingsResolver::class)->forInvoice($this));

            return app(PaymentPurposeRenderer::class)->forInvoice($this, $settings);
        });
    }

    /**
     * Warnings of the invoice, which never stop it: a payment purpose in a foreign currency longer than a SWIFT
     * reference or with characters outside of its set, and a currency other than the contract's.
     *
     * @return Attribute
     */
    protected function warnings(): Attribute
    {
        return Attribute::get(function () {
            $warnings = app(PaymentPurposeRenderer::class)->warnings($this->payment_purpose_preview, $this->currency);

            if ($this->contract !== null && $this->contract->currency !== $this->currency) {
                $warnings[] = 'currency_differs_from_contract';
            }

            return $warnings;
        });
    }

    /**
     * Whether the invoice may return to its draft: it is ready and has no receipts.
     *
     * @return Attribute
     */
    protected function canUnfreeze(): Attribute
    {
        return Attribute::get(fn () => $this->status === InvoiceStatus::Ready
            && !Payment::query()->where('invoice_id', $this->getKey())->exists());
    }

    /**
     * Get the query builder of the model.
     *
     * @param mixed $query
     *
     * @return InvoiceQuery
     */
    public function newEloquentBuilder($query): InvoiceQuery
    {
        return new InvoiceQuery($query);
    }
}
