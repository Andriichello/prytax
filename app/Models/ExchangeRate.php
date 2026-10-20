<?php

namespace PryTax\Models;

use Carbon\Carbon;
use Core\Models\BaseModel;
use Database\Factories\ExchangeRateFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use PryTax\Queries\GlobalQuery;

/**
 * Class ExchangeRate.
 *
 * The NBU's official UAH rate of a currency on a date, cached. It is a cache, never the truth for historical receipts,
 * which keep the rate they were saved with. A rate, which a user entered when the NBU couldn't be reached, is never
 * cached here: it stays on the receipt, refund or transfer it was entered for, so it reaches no other record.
 *
 * @property int $id
 * @property string $currency
 * @property Carbon $rate_date
 * @property string $rate
 * @property Carbon|null $fetched_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static GlobalQuery query()
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Models
 */
#[UseFactory(ExchangeRateFactory::class)]
class ExchangeRate extends BaseModel
{
    /** @use HasFactory<ExchangeRateFactory> */
    use HasFactory;

    /**
     * The attributes that are mass-assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'currency',
        'rate_date',
        'rate',
        'fetched_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'rate_date' => 'date:Y-m-d',
        'rate' => 'decimal:4',
        'fetched_at' => 'datetime',
    ];

    /**
     * Get the query builder of the model.
     *
     * @param mixed $query
     *
     * @return GlobalQuery
     */
    public function newEloquentBuilder($query): GlobalQuery
    {
        return new GlobalQuery($query);
    }
}
