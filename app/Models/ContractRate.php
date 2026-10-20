<?php

namespace PryTax\Models;

use Carbon\Carbon;
use Core\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Class ContractRate.
 *
 * An hourly rate of a contract from a date, for a charge type, or the contract's default without one. It is reached
 * through its contract, which the contract's form saves along with its rates.
 *
 * @property int $id
 * @property int $contract_id
 * @property int|null $charge_type_id
 * @property Carbon $effective_from
 * @property string $hourly_rate
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @property Contract $contract
 * @property ChargeType|null $chargeType
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Models
 */
class ContractRate extends BaseModel
{
    /**
     * The attributes that are mass-assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'charge_type_id',
        'effective_from',
        'hourly_rate',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'charge_type_id' => 'int',
        'effective_from' => 'date:Y-m-d',
        'hourly_rate' => 'decimal:2',
    ];

    /**
     * Contract of the rate.
     *
     * @return BelongsTo
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /**
     * Charge type of the rate, or null for the contract's default.
     *
     * @return BelongsTo
     */
    public function chargeType(): BelongsTo
    {
        return $this->belongsTo(ChargeType::class);
    }
}
