<?php

namespace PryTax\DataLifecycle\Backup\Models;

use Carbon\Carbon;
use Core\Models\BaseModel;
use Core\Models\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use PryTax\DataLifecycle\Backup\Enums\BackupKind;
use PryTax\DataLifecycle\Backup\Enums\BackupStatus;
use PryTax\DataLifecycle\Backup\Queries\OrganizationRecordQuery;

/**
 * Class Backup.
 *
 * A profile's outcome in a run (backups.md §4.1): its archive in the backups bucket, or a run, which skipped it as
 * unchanged or failed it, or an archive, which retention has pruned. Daily, weekly and monthly backups are chosen
 * when pruning, from `created_at`; the tier is the run's kind.
 *
 * @property string $id
 * @property string $run_id
 * @property int $organization_id
 * @property int $profile_id
 * @property BackupStatus $status
 * @property BackupKind $tier
 * @property string|null $disk
 * @property string|null $object_path
 * @property string|null $file_name
 * @property int|null $bytes
 * @property string|null $sha256
 * @property string|null $content_hash
 * @property array<string, int>|null $row_counts
 * @property array<string, mixed>|null $manifest
 * @property int|null $format_version
 * @property string|null $registry_hash
 * @property string|null $schema_version
 * @property bool $include_documents
 * @property Carbon|null $snapshot_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $pruned_at
 * @property Carbon|null $verified_at
 * @property string|null $error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @property BackupRun $run
 *
 * @method static OrganizationRecordQuery query()
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Backup\Models
 */
class Backup extends BaseModel
{
    use BelongsToOrganization;
    use HasUlids;

    /**
     * The attributes that are mass-assignable, which lists filter by too.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'run_id',
        'profile_id',
        'status',
        'tier',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'disk',
        'object_path',
        'manifest',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'status' => BackupStatus::class,
        'tier' => BackupKind::class,
        'bytes' => 'int',
        'row_counts' => 'array',
        'manifest' => 'array',
        'format_version' => 'int',
        'include_documents' => 'boolean',
        'snapshot_at' => 'datetime',
        'expires_at' => 'datetime',
        'pruned_at' => 'datetime',
        'verified_at' => 'datetime',
    ];

    /**
     * Run of the backup.
     *
     * @return BelongsTo
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(BackupRun::class, 'run_id');
    }

    /**
     * Determine if the backup has an archive, which can be downloaded.
     *
     * @return bool
     */
    public function hasArchive(): bool
    {
        return $this->status === BackupStatus::Completed && $this->object_path !== null;
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
