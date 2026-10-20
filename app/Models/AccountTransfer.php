<?php

namespace PryTax\Models;

use Carbon\Carbon;
use Core\Models\BaseModel;
use Core\Models\Traits\Audited;
use Core\Models\Traits\TracksCreator;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use PryTax\Models\Traits\BelongsToProfile;
use PryTax\Queries\TenantQuery;

/**
 * Class AccountTransfer.
 *
 * A movement between two of the profile's own accounts, such as Payoneer → Wise → ФОП bank: never income. Its
 * allocations name the receipts it carries. A same-currency transfer's fee is `amount_sent − amount_received`; an FX
 * transfer has an explicit fee, and the rest of the gap against the NBU rate of `received_at` is spread.
 *
 * @property int $id
 * @property int $profile_id
 * @property int $from_account_id
 * @property int $to_account_id
 * @property int|null $created_by_user_id
 * @property string $amount_sent
 * @property string $currency_sent
 * @property string $amount_received
 * @property string $currency_received
 * @property string|null $fee_amount
 * @property string|null $fee_currency
 * @property string|null $nbu_rate
 * @property Carbon|null $sent_at
 * @property Carbon $received_at
 * @property string|null $reference
 * @property string|null $note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @property Account $fromAccount
 * @property Account $toAccount
 *
 * @method static TenantQuery query()
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Models
 */
class AccountTransfer extends BaseModel
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
        'to_account_id',
        'amount_sent',
        'amount_received',
        'fee_amount',
        'sent_at',
        'received_at',
        'reference',
        'note',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'from_account_id' => 'int',
        'to_account_id' => 'int',
        'amount_sent' => 'decimal:2',
        'amount_received' => 'decimal:2',
        'fee_amount' => 'decimal:2',
        'nbu_rate' => 'decimal:4',
        'sent_at' => 'date:Y-m-d',
        'received_at' => 'date:Y-m-d',
    ];

    /**
     * Account, from which the money was sent.
     *
     * @return BelongsTo
     */
    public function fromAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'from_account_id');
    }

    /**
     * Account, to which the money was sent.
     *
     * @return BelongsTo
     */
    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'to_account_id');
    }

    /**
     * Receipts, which the transfer carries, and how much of each.
     *
     * @return HasMany
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(TransferAllocation::class, 'transfer_id');
    }

    /**
     * Determine if the transfer changes the currency.
     *
     * @return bool
     */
    public function isFx(): bool
    {
        return $this->currency_sent !== $this->currency_received;
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
