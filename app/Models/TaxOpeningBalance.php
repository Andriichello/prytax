<?php

namespace PryTax\Models;

use Carbon\Carbon;
use Core\Models\BaseModel;
use PryTax\Enums\TaxType;
use PryTax\Models\Traits\BelongsToProfile;
use PryTax\Queries\TenantQuery;

/**
 * Class TaxOpeningBalance.
 *
 * The balance of a tax on a date, which seeds its running balance, when the books start mid-history: positive is
 * overpaid, such as ЄП +51.00 on 2026-01-01, negative is owed.
 *
 * @property int $id
 * @property int $profile_id
 * @property TaxType $tax_type
 * @property Carbon $as_of
 * @property string $amount
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static TenantQuery query()
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Models
 */
class TaxOpeningBalance extends BaseModel
{
    use BelongsToProfile;

    /**
     * The attributes that are mass-assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'tax_type',
        'as_of',
        'amount',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'tax_type' => TaxType::class,
        'as_of' => 'date:Y-m-d',
        'amount' => 'decimal:2',
    ];

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
