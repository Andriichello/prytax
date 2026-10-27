<?php

namespace PryTax\DataLifecycle\Retention\Models;

use Carbon\Carbon;
use Core\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use PryTax\DataLifecycle\Retention\Enums\RetentionStatus;

/**
 * Class RetentionJob.
 *
 * One family of a deletion run (backups.md §9.4, §9.5): due at `due_at`, after every job of the run before it in
 * `sequence`, claimed by one worker only, done by its family's handler, with what it processed, deleted and
 * anonymized. A failed one is put back to pending by ops; handlers are idempotent.
 *
 * @property string $id
 * @property string $run_id
 * @property string $family
 * @property string $action `hard_delete` or `anonymize`.
 * @property int $sequence
 * @property Carbon $due_at
 * @property RetentionStatus $status
 * @property int $attempts
 * @property int $records_processed
 * @property int $records_deleted
 * @property int $records_anonymized
 * @property int $objects_deleted
 * @property array|null $payload
 * @property string|null $error
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 *
 * @property RetentionRun $run
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Retention\Models
 */
class RetentionJob extends BaseModel
{
    use HasUlids;

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'data_retention_jobs';

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'due_at' => 'datetime',
        'status' => RetentionStatus::class,
        'payload' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
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
     * Run of the job.
     *
     * @return BelongsTo
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(RetentionRun::class, 'run_id');
    }
}
