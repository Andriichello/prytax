<?php

namespace PryTax\Models;

use Carbon\Carbon;
use Core\Models\BaseModel;
use Core\Models\Traits\Audited;
use Core\Models\Traits\TracksCreator;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use PryTax\Enums\AccountKind;
use PryTax\Enums\LimitCounts;
use PryTax\Enums\LimitPeriod;
use PryTax\Enums\LimitWindow;
use PryTax\Models\Traits\BelongsToProfile;
use PryTax\Queries\TenantQuery;
use PryTax\Services\Accounts\AccountBalanceCalculator;
use PryTax\Services\Accounts\AccountLimitCalculator;

/**
 * Class Account.
 *
 * A place, where money lands, one per currency (Wise USD and Wise EUR are two accounts): a receipt destination, a
 * transfer endpoint and a tax payment source at once. Its requisites are printed into invoices, with the IBAN and
 * the account number encrypted. It has a balance of the tracked flows and an optional limit. Inactive accounts can't
 * be chosen for new invoices or receipts, but keep their history; they are never deleted once referenced.
 *
 * @property int $id
 * @property int $profile_id
 * @property int|null $created_by_user_id
 * @property string $name
 * @property AccountKind $kind
 * @property string $currency
 * @property string|null $holder_name
 * @property string|null $bank_name
 * @property string|null $iban
 * @property string|null $swift
 * @property string|null $account_number
 * @property array|null $details
 * @property string|null $limit_amount
 * @property LimitPeriod|null $limit_period
 * @property LimitWindow $limit_window
 * @property LimitCounts $limit_counts
 * @property bool $is_active
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @property-read string $balance
 * @property-read array|null $limit_progress
 *
 * @method static TenantQuery query()
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Models
 */
#[UseFactory(AccountFactory::class)]
class Account extends BaseModel
{
    use Audited;
    use BelongsToProfile;

    /** @use HasFactory<AccountFactory> */
    use HasFactory;

    use TracksCreator;

    /**
     * The attributes that are mass-assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'kind',
        'currency',
        'holder_name',
        'bank_name',
        'iban',
        'swift',
        'account_number',
        'details',
        'limit_amount',
        'limit_period',
        'limit_window',
        'limit_counts',
        'is_active',
        'sort_order',
    ];

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'limit_window' => 'calendar',
        'limit_counts' => 'receipts',
        'is_active' => true,
        'sort_order' => 0,
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'kind' => AccountKind::class,
        'iban' => 'encrypted',
        'account_number' => 'encrypted',
        'details' => 'array',
        'limit_amount' => 'decimal:2',
        'limit_period' => LimitPeriod::class,
        'limit_window' => LimitWindow::class,
        'limit_counts' => LimitCounts::class,
        'is_active' => 'boolean',
        'sort_order' => 'int',
    ];

    /**
     * Opening balances of the account.
     *
     * @return HasMany
     */
    public function openingBalances(): HasMany
    {
        return $this->hasMany(AccountOpeningBalance::class);
    }

    /**
     * Non-taxable movements of the account.
     *
     * @return HasMany
     */
    public function movements(): HasMany
    {
        return $this->hasMany(AccountMovement::class);
    }

    /**
     * Receipts, which landed on the account.
     *
     * @return HasMany
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'received_to_account_id');
    }

    /**
     * Transfers out of the account.
     *
     * @return HasMany
     */
    public function transfersOut(): HasMany
    {
        return $this->hasMany(AccountTransfer::class, 'from_account_id');
    }

    /**
     * Transfers into the account.
     *
     * @return HasMany
     */
    public function transfersIn(): HasMany
    {
        return $this->hasMany(AccountTransfer::class, 'to_account_id');
    }

    /**
     * Tax payments, which were paid from the account.
     *
     * @return HasMany
     */
    public function taxPayments(): HasMany
    {
        return $this->hasMany(TaxPayment::class, 'paid_from_account_id');
    }

    /**
     * Determine if the account is a platform, such as Payoneer or Wise.
     *
     * @return bool
     */
    public function isPlatform(): bool
    {
        return $this->kind === AccountKind::Platform;
    }

    /**
     * Determine if money on the account should reach a Ukrainian ФОП account by the repatriation rule: a platform's
     * or a foreign bank's.
     *
     * @return bool
     */
    public function needsRepatriation(): bool
    {
        return in_array($this->kind, [AccountKind::Platform, AccountKind::ForeignBank], true);
    }

    /**
     * Balance of the tracked flows, in the account's currency (see AccountBalanceCalculator).
     *
     * @return Attribute
     */
    protected function balance(): Attribute
    {
        return Attribute::get(fn () => app(AccountBalanceCalculator::class)->balance($this));
    }

    /**
     * Progress towards the account's limit, or null, when it has none (see AccountLimitCalculator).
     *
     * @return Attribute
     */
    protected function limitProgress(): Attribute
    {
        return Attribute::get(fn () => app(AccountLimitCalculator::class)->progress($this));
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
