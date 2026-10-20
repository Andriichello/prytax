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
use PryTax\Enums\InvoiceSource;
use PryTax\Enums\InvoiceStatus;
use PryTax\Helpers\Decimal;
use PryTax\Models\Traits\BelongsToProfile;
use PryTax\Queries\InvoiceQuery;

/**
 * Class Invoice.
 *
 * An invoice of a client (design.md §4): draft → ready → sent → paid, or cancelled from ready or sent. The freeze
 * (draft → ready) allocates the number, takes a snapshot of the profile, client, contract, account and items, and
 * renders the PDF from it; past the draft the invoice is locked, except for its status, its dates of sending, payment
 * and cancellation, its notes and its primary file. A ready invoice without receipts may go back to its draft.
 * External invoices (`source = external`) are uploaded PDFs, whose total is entered by hand.
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
     * Version of the snapshot's structure, which the PDF template reads.
     *
     * @var int
     */
    public const int SNAPSHOT_SCHEMA_VERSION = 1;

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
        'subtotal' => 'decimal:2',
        'adjustment_amount' => 'decimal:2',
        'total' => 'decimal:2',
        'status' => InvoiceStatus::class,
        'locked_at' => 'datetime',
        'sent_at' => 'date:Y-m-d',
        'paid_at' => 'date:Y-m-d',
        'cancelled_at' => 'date:Y-m-d',
        'snapshot' => 'array',
        'primary_file_id' => 'int',
    ];

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
     * sent; a paid or cancelled one never changes again.
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
