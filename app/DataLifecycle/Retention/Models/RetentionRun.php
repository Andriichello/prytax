<?php

namespace PryTax\DataLifecycle\Retention\Models;

use Carbon\Carbon;
use Core\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use PryTax\DataLifecycle\Retention\Enums\RetentionStatus;

/**
 * Class RetentionRun.
 *
 * A deletion of a subject (a user, a profile, an organization) or the daily rolling cleanup (`global`), as a series
 * of jobs, one per family, each due when its grace period ends (backups.md §9.3, §9.4). One subject has one active
 * run at a time. Its status follows its jobs.
 *
 * @property string $id
 * @property string $subject_type `user`, `profile`, `organization` or `global`.
 * @property int|null $subject_id
 * @property int|null $organization_id
 * @property string $trigger
 * @property bool $immediate
 * @property int|null $requested_by_user_id
 * @property RetentionStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $completed_at
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Retention\Models
 */
class RetentionRun extends BaseModel
{
    use HasUlids;

    /**
     * The name of the "updated at" column: runs have none.
     *
     * @var string|null
     */
    public const ?string UPDATED_AT = null;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'data_retention_runs';

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'immediate' => 'boolean',
        'status' => RetentionStatus::class,
        'cancelled_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
    ];

    /**
     * Jobs of the run, in their order.
     *
     * @return HasMany
     */
    public function jobs(): HasMany
    {
        return $this->hasMany(RetentionJob::class, 'run_id')->orderBy('sequence');
    }

    /**
     * Get the job of a family.
     *
     * @param string $family
     *
     * @return RetentionJob|null
     */
    public function job(string $family): ?RetentionJob
    {
        return $this->jobs()->where('family', $family)->first();
    }

    /**
     * Derive the status from the jobs: failed, when one failed; completed, when every one, which isn't cancelled, is.
     *
     * @return void
     */
    public function refreshStatus(): void
    {
        if ($this->status === RetentionStatus::Cancelled) {
            return;
        }

        $statuses = $this->jobs()->pluck('status')->map(fn ($status) => $status instanceof RetentionStatus ? $status : RetentionStatus::from($status));
        $active = $statuses->reject(fn (RetentionStatus $status) => $status === RetentionStatus::Cancelled);

        $status = match (true) {
            $active->contains(RetentionStatus::Failed) => RetentionStatus::Failed,
            $active->every(fn (RetentionStatus $status) => $status === RetentionStatus::Completed) => RetentionStatus::Completed,
            $active->contains(fn (RetentionStatus $status) => $status !== RetentionStatus::Pending) => RetentionStatus::Running,
            default => RetentionStatus::Pending,
        };

        $this->forceFill([
            'status' => $status,
            'completed_at' => $status === RetentionStatus::Completed ? ($this->completed_at ?? now()) : null,
        ])->save();
    }
}
