<?php

namespace PryTax\Models;

use Carbon\Carbon;
use Core\Models\BaseModel;
use Core\Models\Traits\Audited;
use Core\Models\Traits\TracksCreator;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use PryTax\Enums\PaymentKind;
use PryTax\Models\Traits\BelongsToProfile;
use PryTax\Queries\PaymentQuery;
use PryTax\Services\Payments\PaymentFlow;

/**
 * Class Payment.
 *
 * A receipt: one credit of client money to one of the profile's accounts, which is exactly one taxable event
 * (design.md §2.1). `amount` in the invoice's `currency` is the taxable base, also when the bank or platform kept a
 * fee on the way in; what landed is `received_amount` in the account's currency. `received_at` is the tax date, and
 * the NBU rate, UAH amount, tax rule and per-receipt ЄП and ВЗ (hints, not the declaration figure) are snapshotted
 * when it is saved, and recomputed only when the amount, currency or date change. The period columns are generated
 * from `received_at`.
 *
 * @property int $id
 * @property int $profile_id
 * @property int|null $invoice_id
 * @property int|null $created_by_user_id
 * @property PaymentKind $kind
 * @property string|null $description
 * @property string|null $receipt_group_id
 * @property string $currency
 * @property string $amount
 * @property string|null $client_fee_amount
 * @property int $received_to_account_id
 * @property string $received_amount
 * @property string $received_currency
 * @property string|null $receipt_rate
 * @property Carbon $received_at
 * @property string $nbu_rate
 * @property Carbon $nbu_rate_date
 * @property int|null $exchange_rate_id
 * @property bool $rate_is_manual
 * @property string $uah_amount
 * @property int $tax_rule_id
 * @property string $single_tax_uah
 * @property string $military_levy_uah
 * @property int $period_year
 * @property int $period_quarter
 * @property int $period_month
 * @property Carbon|null $repatriation_ack_at
 * @property int|null $source_transfer_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @property Invoice|null $invoice
 * @property Account $receivedToAccount
 * @property TaxRule $taxRule
 *
 * @method static PaymentQuery query()
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Models
 */
#[UseFactory(PaymentFactory::class)]
class Payment extends BaseModel
{
    use Audited;
    use BelongsToProfile;

    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    use TracksCreator;

    /**
     * The attributes that are mass-assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'invoice_id',
        'kind',
        'description',
        'receipt_group_id',
        'currency',
        'amount',
        'client_fee_amount',
        'received_to_account_id',
        'received_amount',
        'received_currency',
        'received_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'invoice_id' => 'int',
        'kind' => PaymentKind::class,
        'amount' => 'decimal:2',
        'client_fee_amount' => 'decimal:2',
        'received_to_account_id' => 'int',
        'received_amount' => 'decimal:2',
        'receipt_rate' => 'decimal:4',
        'received_at' => 'date:Y-m-d',
        'nbu_rate' => 'decimal:4',
        'nbu_rate_date' => 'date:Y-m-d',
        'exchange_rate_id' => 'int',
        'rate_is_manual' => 'boolean',
        'uah_amount' => 'decimal:2',
        'tax_rule_id' => 'int',
        'single_tax_uah' => 'decimal:2',
        'military_levy_uah' => 'decimal:2',
        'period_year' => 'int',
        'period_quarter' => 'int',
        'period_month' => 'int',
        'repatriation_ack_at' => 'datetime',
        'source_transfer_id' => 'int',
    ];

    /**
     * Invoice of the receipt, unless it is an advance or other income.
     *
     * @return BelongsTo
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * Account, on which the money landed.
     *
     * @return BelongsTo
     */
    public function receivedToAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'received_to_account_id');
    }

    /**
     * Tax rule of the snapshot.
     *
     * @return BelongsTo
     */
    public function taxRule(): BelongsTo
    {
        return $this->belongsTo(TaxRule::class);
    }

    /**
     * Exchange rate of the snapshot.
     *
     * @return BelongsTo
     */
    public function exchangeRate(): BelongsTo
    {
        return $this->belongsTo(ExchangeRate::class);
    }

    /**
     * Refunds of the receipt.
     *
     * @return HasMany
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    /**
     * Shares of transfers, which carried the receipt's money.
     *
     * @return HasMany
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(TransferAllocation::class);
    }

    /**
     * Tax payments allocated to the receipt.
     *
     * @return HasMany
     */
    public function taxPayments(): HasMany
    {
        return $this->hasMany(TaxPayment::class);
    }

    /**
     * The FX transfer, of which this synthetic FX gain was made.
     *
     * @return BelongsTo
     */
    public function sourceTransfer(): BelongsTo
    {
        return $this->belongsTo(AccountTransfer::class, 'source_transfer_id');
    }

    /**
     * Fees, landing, holdings and net income of the receipt (see PaymentFlow::metrics()).
     *
     * @return Attribute
     */
    protected function metrics(): Attribute
    {
        return Attribute::get(fn () => app(PaymentFlow::class)->metrics($this));
    }

    /**
     * Get the query builder of the model.
     *
     * @param mixed $query
     *
     * @return PaymentQuery
     */
    public function newEloquentBuilder($query): PaymentQuery
    {
        return new PaymentQuery($query);
    }
}
