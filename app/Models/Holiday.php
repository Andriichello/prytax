<?php

namespace PryTax\Models;

use Carbon\Carbon;
use Core\Models\BaseModel;
use PryTax\Queries\GlobalQuery;

/**
 * Class Holiday.
 *
 * A public holiday, which isn't a business day for the deadline roll-over. The table is empty while martial law
 * lasts, as holidays are working days then (Закон 2136-IX).
 *
 * @property int $id
 * @property Carbon $date
 * @property string $name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static GlobalQuery query()
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Models
 */
class Holiday extends BaseModel
{
    /**
     * The attributes that are mass-assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'date',
        'name',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'date' => 'date:Y-m-d',
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
