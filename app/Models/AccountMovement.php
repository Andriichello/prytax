<?php

namespace PryTax\Models;

use Carbon\Carbon;
use Core\Models\BaseModel;
use Core\Models\Traits\Audited;
use Core\Models\Traits\TracksCreator;
use Database\Factories\AccountMovementFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use PryTax\Enums\MovementCategory;
use PryTax\Enums\MovementDirection;
use PryTax\Models\Traits\BelongsToProfile;
use PryTax\Queries\TenantQuery;

/**
 * Class AccountMovement.
 *
 * A non-taxable movement of an account, such as a bank fee, interest or an owner's withdrawal, so its balance can
 * reconcile with the statement. It never enters income: passive income isn't ЄП income (п. 292.1).
 *
 * @property int $id
 * @property int $profile_id
 * @property int $account_id
 * @property int|null $created_by_user_id
 * @property MovementDirection $direction
 * @property string $amount
 * @property Carbon $booked_at
 * @property MovementCategory $category
 * @property string|null $note
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
#[UseFactory(AccountMovementFactory::class)]
class AccountMovement extends BaseModel
{
    /** @use HasFactory<AccountMovementFactory> */
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
        'direction',
        'amount',
        'booked_at',
        'category',
        'note',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'direction' => MovementDirection::class,
        'amount' => 'decimal:2',
        'booked_at' => 'date:Y-m-d',
        'category' => MovementCategory::class,
    ];

    /**
     * Account of the movement.
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
