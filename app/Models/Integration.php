<?php

namespace PryTax\Models;

use Carbon\Carbon;
use Core\Models\BaseModel;
use Core\Models\Traits\Audited;
use Core\Models\Traits\BelongsToOrganization;
use Core\Models\User;
use Database\Factories\IntegrationFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use PryTax\Queries\TenantQuery;

/**
 * Class Integration.
 *
 * An organization's integration with an outside tool (builds.md §7), now Claude over MCP: off until its owner turns
 * it on in Налаштування → Інтеграції, which records who did it and when. While on, its connection's key lets the tool
 * read as its acting user (the one who turned it on, unless another is chosen), within the chosen ФОП (all, unless
 * some are). What its other side last did (connected, called) is kept too, outside the audit log.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $kind
 * @property Carbon|null $enabled_at
 * @property int|null $enabled_by_user_id
 * @property Carbon|null $disabled_at
 * @property int|null $disabled_by_user_id
 * @property int|null $acting_user_id
 * @property list<int>|null $profile_ids Null for every ФОП of the organization.
 * @property string|null $key
 * @property string|null $key_hash
 * @property Carbon|null $key_created_at
 * @property Carbon|null $last_connected_at
 * @property array{name?: string, version?: string}|null $last_client
 * @property Carbon|null $last_used_at
 * @property string|null $last_tool
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $enabledBy
 * @property-read User|null $disabledBy
 * @property-read User|null $actingUser
 *
 * @method static TenantQuery query()
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Models
 */
#[UseFactory(IntegrationFactory::class)]
class Integration extends BaseModel
{
    use Audited;
    use BelongsToOrganization;

    /** @use HasFactory<IntegrationFactory> */
    use HasFactory;

    /**
     * Claude, over MCP: a local server, which Claude Code or Claude Desktop starts (`mcp:start prytax`).
     *
     * @var string
     */
    public const string CLAUDE = 'claude';

    /**
     * The attributes that are mass-assignable: its configuration; turning it on and off, and its key, are recorded
     * apart.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'acting_user_id',
        'profile_ids',
    ];

    /**
     * The attributes, which are never shown: the key comes only with its connection, to its owner.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'key',
        'key_hash',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'enabled_at' => 'datetime',
        'disabled_at' => 'datetime',
        'profile_ids' => 'array',
        'key' => 'encrypted',
        'key_created_at' => 'datetime',
        'last_connected_at' => 'datetime',
        'last_client' => 'array',
        'last_used_at' => 'datetime',
    ];

    /**
     * Get the organization's integration of the kind: the stored one, or a new one, which is off.
     *
     * @param int $organizationId
     * @param string $kind
     *
     * @return self
     */
    public static function of(int $organizationId, string $kind): self
    {
        $integration = static::query()
            ->withoutGlobalScope(self::ORGANIZATION_SCOPE)
            ->where('organization_id', $organizationId)
            ->where('kind', $kind)
            ->first();

        if ($integration === null) {
            $integration = new static;
            $integration->forceFill(['organization_id' => $organizationId, 'kind' => $kind]);
        }

        return $integration;
    }

    /**
     * Get the integration, which the key opens, in whichever organization, or null.
     *
     * @param string $key
     *
     * @return self|null
     */
    public static function ofKey(string $key): ?self
    {
        return static::query()
            ->withoutGlobalScope(self::ORGANIZATION_SCOPE)
            ->where('key_hash', self::hashOf($key))
            ->first();
    }

    /**
     * Get the hash of a key, which finds its integration.
     *
     * @param string $key
     *
     * @return string
     */
    public static function hashOf(string $key): string
    {
        return hash('sha256', $key);
    }

    /**
     * Determine whether it's on.
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->enabled_at !== null;
    }

    /**
     * Get the user, as whom its other side reads: the one chosen, else the one who turned it on.
     *
     * @return User|null
     */
    public function actor(): ?User
    {
        return $this->actingUser ?? $this->enabledBy;
    }

    /**
     * Determine whether its other side may see the ФОП.
     *
     * @param int $profileId
     *
     * @return bool
     */
    public function allowsProfile(int $profileId): bool
    {
        return $this->profile_ids === null || in_array($profileId, $this->profile_ids, true);
    }

    /**
     * Get the user, who turned it on.
     *
     * @return BelongsTo
     */
    public function enabledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enabled_by_user_id');
    }

    /**
     * Get the user, who turned it off.
     *
     * @return BelongsTo
     */
    public function disabledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disabled_by_user_id');
    }

    /**
     * Get the user chosen to read as.
     *
     * @return BelongsTo
     */
    public function actingUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acting_user_id');
    }

    /**
     * Get the attributes, which the audit log leaves out: what its other side last did, which isn't a change of it.
     *
     * @return array<int, string>
     */
    public function auditExcluded(): array
    {
        return ['last_connected_at', 'last_client', 'last_used_at', 'last_tool'];
    }

    /**
     * Create a new Eloquent query builder for the model.
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
