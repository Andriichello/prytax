<?php

namespace PryTax\Models;

use Carbon\Carbon;
use Core\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use PryTax\Enums\EsvStatus;
use PryTax\Models\Traits\BelongsToProfile;
use PryTax\Queries\TenantQuery;

/**
 * Class EsvObligation.
 *
 * A month of ЄСВ of a profile, generated from the tax rules independently of income: due, or exempt (the voluntary
 * window of 2022-03 … 2024-12, territorial exemptions). Whether it is paid is derived from the tax payments.
 *
 * @property int $id
 * @property int $profile_id
 * @property int $year
 * @property int $month
 * @property int $tax_rule_id
 * @property string $amount_due
 * @property EsvStatus $status
 * @property string|null $note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @property TaxRule $taxRule
 *
 * @method static TenantQuery query()
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Models
 */
class EsvObligation extends BaseModel
{
    use BelongsToProfile;

    /**
     * The attributes that are mass-assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'status',
        'note',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'year' => 'int',
        'month' => 'int',
        'tax_rule_id' => 'int',
        'amount_due' => 'decimal:2',
        'status' => EsvStatus::class,
    ];

    /**
     * Tax rule, of which the month's amount is.
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
