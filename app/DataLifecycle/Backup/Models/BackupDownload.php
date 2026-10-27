<?php

namespace PryTax\DataLifecycle\Backup\Models;

use Carbon\Carbon;
use Core\Models\BaseModel;
use Core\Models\Traits\BelongsToOrganization;

/**
 * Class BackupDownload.
 *
 * A download link, which was issued of a backup, a bundle or an export (backups.md §3.3): who, from where, when.
 *
 * @property int $id
 * @property string|null $backup_id
 * @property string|null $run_id
 * @property int $organization_id
 * @property int|null $user_id
 * @property string $kind `profile`, `bundle` or `export`.
 * @property string|null $ip
 * @property string|null $user_agent
 * @property Carbon|null $created_at
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Backup\Models
 */
class BackupDownload extends BaseModel
{
    use BelongsToOrganization;

    /**
     * The name of the "updated at" column: downloads are never updated.
     *
     * @var string|null
     */
    public const ?string UPDATED_AT = null;

    /**
     * The attributes that are mass-assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'backup_id',
        'run_id',
        'user_id',
        'kind',
        'ip',
        'user_agent',
    ];
}
