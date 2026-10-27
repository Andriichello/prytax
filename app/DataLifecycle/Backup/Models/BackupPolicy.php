<?php

namespace PryTax\DataLifecycle\Backup\Models;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Core\Models\BaseModel;
use Core\Models\Traits\BelongsToOrganization;
use Illuminate\Support\Facades\DB;
use PryTax\DataLifecycle\Backup\Enums\BackupFrequency;
use PryTax\DataLifecycle\Backup\Enums\BackupNotify;
use PryTax\DataLifecycle\Backup\Queries\OrganizationRecordQuery;
use PryTax\DataLifecycle\Backup\RunSpec;

/**
 * Class BackupPolicy.
 *
 * An organization's backup policy (backups.md §2.2): how often its profiles are backed up, how many backups are
 * kept, what an archive holds, which profiles, and whom it tells. An organization without one has the defaults. A run
 * starts in the platform's window of 01:00–05:00 Kyiv time, at an offset of its own (`crc32(organization) % 240`
 * minutes), which spreads the load.
 *
 * @property int $id
 * @property int $organization_id
 * @property bool $enabled
 * @property BackupFrequency $frequency
 * @property int|null $weekday ISO weekday, 1 (Monday) to 7.
 * @property int|null $month_day 1 to 28.
 * @property int $retention_daily
 * @property int $retention_weekly
 * @property int $retention_monthly
 * @property bool $include_documents
 * @property bool $include_audit_log
 * @property string $profiles_mode `all` or `selected`.
 * @property bool $skip_unchanged
 * @property BackupNotify $notify
 * @property Carbon|null $next_run_at
 * @property Carbon|null $last_run_at
 * @property int|null $updated_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static OrganizationRecordQuery query()
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Backup\Models
 */
class BackupPolicy extends BaseModel
{
    use BelongsToOrganization;

    /**
     * Profiles mode of a policy, which backs up every profile of the organization, new ones too.
     *
     * @var string
     */
    public const string ALL_PROFILES = 'all';

    /**
     * Profiles mode of a policy, which backs up the selected profiles only.
     *
     * @var string
     */
    public const string SELECTED_PROFILES = 'selected';

    /**
     * Option of a run, with which the archives hold the audit log of their profiles.
     *
     * @var string
     */
    public const string INCLUDE_AUDIT_LOG = 'include_audit_log';

    /**
     * Start of the platform's window of runs, in minutes after midnight, Kyiv time.
     *
     * @var int
     */
    public const int WINDOW_START = 60;

    /**
     * Length of the window, in minutes.
     *
     * @var int
     */
    public const int WINDOW_MINUTES = 240;

    /**
     * The attributes that are mass-assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'enabled',
        'frequency',
        'weekday',
        'month_day',
        'retention_daily',
        'retention_weekly',
        'retention_monthly',
        'include_documents',
        'include_audit_log',
        'profiles_mode',
        'skip_unchanged',
        'notify',
    ];

    /**
     * The model's default values for attributes, which an organization without a policy has.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'enabled' => true,
        'frequency' => 'daily',
        'retention_daily' => 7,
        'retention_weekly' => 4,
        'retention_monthly' => 12,
        'include_documents' => true,
        'include_audit_log' => false,
        'profiles_mode' => self::ALL_PROFILES,
        'skip_unchanged' => true,
        'notify' => 'failures',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'enabled' => 'boolean',
        'frequency' => BackupFrequency::class,
        'weekday' => 'int',
        'month_day' => 'int',
        'retention_daily' => 'int',
        'retention_weekly' => 'int',
        'retention_monthly' => 'int',
        'include_documents' => 'boolean',
        'include_audit_log' => 'boolean',
        'skip_unchanged' => 'boolean',
        'notify' => BackupNotify::class,
        'next_run_at' => 'datetime',
        'last_run_at' => 'datetime',
    ];

    /**
     * Get the organization's policy, or a new one with the defaults, which isn't saved.
     *
     * @param int $organizationId
     *
     * @return self
     */
    public static function of(int $organizationId): self
    {
        $policy = static::query()->withoutGlobalScope(self::ORGANIZATION_SCOPE)->where('organization_id', $organizationId)->first();

        if ($policy === null) {
            $policy = new static;
            $policy->forceFill(['organization_id' => $organizationId]);
        }

        return $policy;
    }

    /**
     * Get the options of the policy's runs.
     *
     * @return array<string, bool>
     */
    public function options(): array
    {
        return [
            RunSpec::INCLUDE_DOCUMENTS => $this->include_documents,
            self::INCLUDE_AUDIT_LOG => $this->include_audit_log,
        ];
    }

    /**
     * Get ids of the selected profiles.
     *
     * @return array<int, int>
     */
    public function profileIds(): array
    {
        if (!$this->exists) {
            return [];
        }

        return DB::table('backup_policy_profiles')->where('backup_policy_id', $this->getKey())->orderBy('profile_id')
            ->pluck('profile_id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Select the profiles.
     *
     * @param array<int, int> $ids
     *
     * @return void
     */
    public function syncProfileIds(array $ids): void
    {
        DB::table('backup_policy_profiles')->where('backup_policy_id', $this->getKey())->delete();
        DB::table('backup_policy_profiles')->insert(array_map(
            fn (int $id) => ['backup_policy_id' => $this->getKey(), 'profile_id' => $id],
            array_values(array_unique($ids))
        ));
    }

    /**
     * Get the policy's next run after the moment: the organization's time in the window, on the next day of its
     * frequency.
     *
     * @param CarbonInterface $after
     *
     * @return CarbonImmutable
     */
    public function nextRunAfter(CarbonInterface $after): CarbonImmutable
    {
        $after = CarbonImmutable::instance($after)->setTimezone(config('app.timezone'));
        $minutes = self::WINDOW_START + crc32((string) $this->organization_id) % self::WINDOW_MINUTES;
        $day = $after->startOfDay();

        for ($i = 0; $i < 62; $i++, $day = $day->addDay()) {
            $slot = $day->addMinutes($minutes);

            $due = match ($this->frequency) {
                BackupFrequency::Daily => true,
                BackupFrequency::Weekly => $day->isoWeekday() === ($this->weekday ?? 1),
                BackupFrequency::Monthly => $day->day === ($this->month_day ?? 1),
            };

            if ($due && $slot->greaterThan($after)) {
                return $slot;
            }
        }

        return $after->addDay();
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
