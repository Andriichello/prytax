<?php

namespace PryTax\Models;

use Carbon\Carbon;
use Core\Models\BaseModel;
use PryTax\Enums\ExchangeRateSource;
use PryTax\Queries\GlobalQuery;

/**
 * Class ExchangeRate.
 *
 * The UAH rate of a currency on a date: the NBU's official one, cached, or one a user entered when the NBU couldn't be
 * reached. It is a cache, never the truth for historical receipts, which keep the rate they were saved with.
 *
 * @property int $id
 * @property string $currency
 * @property Carbon $rate_date
 * @property string $rate
 * @property ExchangeRateSource $source
 * @property Carbon|null $fetched_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static GlobalQuery query()
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Models
 */
class ExchangeRate extends BaseModel
{
    /**
     * The attributes that are mass-assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'currency',
        'rate_date',
        'rate',
        'source',
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
        'source' => ExchangeRateSource::class,
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
