<?php

namespace PryTax\Models;

use Carbon\Carbon;
use Core\Models\BaseModel;
use Core\Models\Traits\Audited;
use Core\Models\Traits\TracksCreator;
use Database\Factories\TaxPaymentFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use PryTax\Enums\TaxAllocation;
use PryTax\Enums\TaxPaymentStatus;
use PryTax\Enums\TaxType;
use PryTax\Models\Traits\BelongsToProfile;
use PryTax\Queries\TenantQuery;

/**
 * Class TaxPayment.
 *
 * A payment of ЄП, ВЗ or ЄСВ, allocated to a receipt, a month or a quarter (design.md §8.2, §8.4): ЄП and ВЗ are paid
 * from a receipt, ЄСВ by month; a quarter's allocation is left for imported history. The period columns are always
 * filled, so a quarter reconciles with one query; for a receipt, they are copied from its `received_at`. A sent
 * payment is pending (shown, not counted), a failed one is ignored. Paid from a foreign-currency account, it keeps
 * what left that account (`original_amount` in `original_currency`).
 *
 * @property int $id
 * @property int $profile_id
 * @property int|null $created_by_user_id
 * @property TaxType $tax_type
 * @property string $amount
 * @property Carbon|null $paid_at
 * @property TaxPaymentStatus $status
 * @property TaxAllocation $allocation
 * @property int|null $payment_id
 * @property int $period_year
 * @property int $period_quarter
 * @property int|null $period_month
 * @property int|null $paid_from_account_id
 * @property string|null $original_amount
 * @property string|null $original_currency
 * @property string|null $reference
 * @property string|null $note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @property Payment|null $payment
 * @property Account|null $paidFromAccount
 *
 * @method static TenantQuery query()
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Models
 */
#[UseFactory(TaxPaymentFactory::class)]
class TaxPayment extends BaseModel
{
    /** @use HasFactory<TaxPaymentFactory> */
    use HasFactory;

    use Audited;
    use BelongsToProfile;
    use TracksCreator;

    /**
     * The attributes that are mass-assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'tax_type',
        'amount',
        'paid_at',
        'status',
        'allocation',
        'payment_id',
        'period_year',
        'period_quarter',
        'period_month',
        'paid_from_account_id',
        'original_amount',
        'original_currency',
        'reference',
        'note',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'tax_type' => TaxType::class,
        'amount' => 'decimal:2',
        'paid_at' => 'date:Y-m-d',
        'status' => TaxPaymentStatus::class,
        'allocation' => TaxAllocation::class,
        'payment_id' => 'int',
        'period_year' => 'int',
        'period_quarter' => 'int',
        'period_month' => 'int',
        'paid_from_account_id' => 'int',
        'original_amount' => 'decimal:2',
    ];

    /**
     * Receipt, to which the tax payment is allocated.
     *
     * @return BelongsTo
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * Account, from which the tax was paid.
     *
     * @return BelongsTo
     */
    public function paidFromAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'paid_from_account_id');
    }

    /**
     * Get the query builder of the model.
     *
     * @param mixed $query
     *
     * @return TenantQuery
     */
    public function newEloquentBuilder($query): TenantQuery
    {
        return new TenantQuery($query);
    }
}
