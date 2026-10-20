<?php

namespace PryTax\Models;

use Carbon\Carbon;
use Core\Models\BaseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;

/**
 * Class IdempotencyKey.
 *
 * The stored response of a POST, which carried an `Idempotency-Key`, so a replay (a double click) answers it again
 * rather than creating a second record. Keys are pruned after 24 hours.
 *
 * @property int $id
 * @property int $user_id
 * @property string $key
 * @property string $request_hash
 * @property int $response_status
 * @property string $response_body
 * @property Carbon|null $created_at
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Models
 */
class IdempotencyKey extends BaseModel
{
    use MassPrunable;

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The attributes that are mass-assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'key',
        'request_hash',
        'response_status',
        'response_body',
        'created_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'user_id' => 'int',
        'response_status' => 'int',
        'created_at' => 'datetime',
    ];

    /**
     * Get the prunable model query: keys older than 24 hours.
     *
     * @return Builder
     */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDay());
    }
}
