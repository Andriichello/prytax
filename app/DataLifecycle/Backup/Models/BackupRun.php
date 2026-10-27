<?php

namespace PryTax\DataLifecycle\Backup\Models;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Core\Models\BaseModel;
use Core\Models\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use PryTax\DataLifecycle\Backup\Enums\BackupKind;
use PryTax\DataLifecycle\Backup\Enums\RunStatus;
use PryTax\DataLifecycle\Backup\Queries\OrganizationRecordQuery;
use PryTax\DataLifecycle\Backup\RunSpec;

/**
 * Class BackupRun.
 *
 * One execution of an organization's backup at a single snapshot instant (backups.md §4.1, §4.3): an archive per
 * included profile. It keeps the policy's options and profiles as they were when it was started, so a retry does
 * the same, and the organization's bundle of its archives (§4.5).
 *
 * @property string $id
 * @property int $organization_id
 * @property BackupKind $kind
 * @property array{type: string, from?: string, to?: string} $scope
 * @property array<string, bool> $options
 * @property array<int, int>|null $profile_ids
 * @property RunStatus $status
 * @property Carbon|null $snapshot_at
 * @property int|null $triggered_by_user_id
 * @property string|null $reason
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property string|null $error
 * @property string|null $bundle_status
 * @property string|null $bundle_disk
 * @property string|null $bundle_path
 * @property Carbon|null $bundle_expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static OrganizationRecordQuery query()
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Backup\Models
 */
class BackupRun extends BaseModel
{
    use BelongsToOrganization;
    use HasUlids;

    /**
     * The attributes that are mass-assignable, which lists filter by too.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'kind',
        'status',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'bundle_disk',
        'bundle_path',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'kind' => BackupKind::class,
        'scope' => 'array',
        'options' => 'array',
        'profile_ids' => 'array',
        'status' => RunStatus::class,
        'snapshot_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'bundle_expires_at' => 'datetime',
    ];

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'queued',
    ];

    /**
     * Backups of the run, one per profile.
     *
     * @return HasMany
     */
    public function backups(): HasMany
    {
        return $this->hasMany(Backup::class, 'run_id');
    }

    /**
     * Get what the run exports.
     *
     * @return RunSpec
     */
    public function spec(): RunSpec
    {
        $period = ($this->scope['type'] ?? 'full') === 'period';

        return new RunSpec(
            $this->kind->value,
            $this->options ?? [],
            $period ? CarbonImmutable::parse($this->scope['from']) : null,
            $period ? CarbonImmutable::parse($this->scope['to']) : null,
        );
    }

    /**
     * Get the query builder of the model.
     *
     * @param mixed $query
     *
     * @return OrganizationRecordQuery
     */
    public function newEloquentBuilder($query): OrganizationRecordQuery
    {
        return new OrganizationRecordQuery($query);
    }
}
