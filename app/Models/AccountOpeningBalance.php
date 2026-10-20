<?php

namespace PryTax\Models;

use Carbon\Carbon;
use Core\Models\BaseModel;
use Core\Models\Traits\Audited;
use Core\Models\Traits\TracksCreator;
use Database\Factories\AccountOpeningBalanceFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use PryTax\Models\Traits\BelongsToProfile;
use PryTax\Queries\TenantQuery;

/**
 * Class AccountOpeningBalance.
 *
 * The balance of an account on a date, from which its tracked flows are counted, for accounts that existed before
 * the books started in PryTax.
 *
 * @property int $id
 * @property int $profile_id
 * @property int|null $created_by_user_id
 * @property int $account_id
 * @property Carbon $as_of
 * @property string $amount
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @property Account $account
 *
 * @method static TenantQuery query()
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Models
 */
#[UseFactory(AccountOpeningBalanceFactory::class)]
class AccountOpeningBalance extends BaseModel
{
    /** @use HasFactory<AccountOpeningBalanceFactory> */
    use HasFactory;

    use Audited;
    use TracksCreator;
    use BelongsToProfile;

    /**
     * The attributes that are mass-assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'as_of',
        'amount',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'as_of' => 'date:Y-m-d',
        'amount' => 'decimal:2',
    ];

    /**
     * Account of the balance.
     *
     * @return BelongsTo
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
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
