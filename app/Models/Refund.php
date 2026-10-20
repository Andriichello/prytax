<?php

namespace PryTax\Models;

use Carbon\Carbon;
use Core\Models\BaseModel;
use Core\Models\Traits\Audited;
use Core\Models\Traits\TracksCreator;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use PryTax\Models\Traits\BelongsToProfile;
use PryTax\Queries\TenantQuery;

/**
 * Class Refund.
 *
 * Money returned to a client from a receipt. It reduces income in the period of the refund (п. 292.11), at the NBU
 * rate of its date, and reduces what the receipts of the invoice cover. Refunds of a receipt never exceed its amount.
 *
 * @property int $id
 * @property int $profile_id
 * @property int $payment_id
 * @property int $from_account_id
 * @property int|null $created_by_user_id
 * @property string $amount
 * @property string $currency
 * @property Carbon $refunded_at
 * @property string $nbu_rate
 * @property Carbon $nbu_rate_date
 * @property int|null $exchange_rate_id
 * @property bool $rate_is_manual
 * @property string $uah_amount
 * @property int $tax_rule_id
 * @property string|null $reason
 * @property int $period_year
 * @property int $period_quarter
 * @property int $period_month
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @property Payment $payment
 * @property Account $fromAccount
 *
 * @method static TenantQuery query()
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Models
 */
class Refund extends BaseModel
{
    use Audited;
    use BelongsToProfile;
    use TracksCreator;

    /**
     * The attributes that are mass-assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'from_account_id',
        'amount',
        'refunded_at',
        'reason',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'payment_id' => 'int',
        'from_account_id' => 'int',
        'amount' => 'decimal:2',
        'refunded_at' => 'date:Y-m-d',
        'nbu_rate' => 'decimal:4',
        'nbu_rate_date' => 'date:Y-m-d',
        'exchange_rate_id' => 'int',
        'rate_is_manual' => 'boolean',
        'uah_amount' => 'decimal:2',
        'tax_rule_id' => 'int',
        'period_year' => 'int',
        'period_quarter' => 'int',
        'period_month' => 'int',
    ];

    /**
     * Receipt, which is refunded.
     *
     * @return BelongsTo
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * Account, from which the money was returned.
     *
     * @return BelongsTo
     */
    public function fromAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'from_account_id');
    }

    /**
     * Tax rule of the refund's date, whose rates its hints take back.
     *
     * @return BelongsTo
     */
    public function taxRule(): BelongsTo
    {
        return $this->belongsTo(TaxRule::class);
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
