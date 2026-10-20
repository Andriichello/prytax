<?php

namespace PryTax\Models;

use Carbon\Carbon;
use Core\Models\BaseModel;
use Core\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use PryTax\Enums\AlertCategory;
use PryTax\Enums\AlertSeverity;
use PryTax\Models\Traits\BelongsToProfile;
use PryTax\Queries\AlertQuery;

/**
 * Class Alert.
 *
 * A notification of a profile ("Сповіщення", design.md §9): one row per condition (`dedupe_key`), which AlertSync
 * upserts while the condition holds and resolves when it clears, never deleting it. It informs and never blocks.
 * Read state is per user, and is written only when the user taps the notification, its action or its ✓, never when
 * the list is loaded. (api-core's `notifications` are another thing: database notifications of a user.)
 *
 * @property int $id
 * @property int $profile_id
 * @property string $type
 * @property AlertCategory $category
 * @property AlertSeverity $severity
 * @property string $dedupe_key
 * @property string $title
 * @property string|null $body
 * @property array|null $payload
 * @property string|null $action_label
 * @property string|null $action_route
 * @property Carbon|null $resolved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static AlertQuery query()
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Models
 */
class Alert extends BaseModel
{
    use BelongsToProfile;

    /**
     * The attributes that are mass-assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'type',
        'category',
        'severity',
        'dedupe_key',
        'title',
        'body',
        'payload',
        'action_label',
        'action_route',
        'resolved_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'category' => AlertCategory::class,
        'severity' => AlertSeverity::class,
        'payload' => 'array',
        'resolved_at' => 'datetime',
        'read_at' => 'datetime',
    ];

    /**
     * Users, who read the notification.
     *
     * @return BelongsToMany
     */
    public function readers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'alert_reads')->withPivot('read_at');
    }

    /**
     * Get the query builder of the model.
     *
     * @param mixed $query
     *
     * @return AlertQuery
     */
    public function newEloquentBuilder($query): AlertQuery
    {
        return new AlertQuery($query);
    }
}
