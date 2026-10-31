<?php

namespace PryTax\Services\Installation;

use Carbon\CarbonImmutable;
use Core\Models\File;
use Core\Models\Organization;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PryTax\DataLifecycle\Backup\Enums\BackupKind;
use PryTax\DataLifecycle\Backup\Enums\BackupStatus;
use PryTax\DataLifecycle\Backup\Models\Backup;
use PryTax\Models\Invoice;
use PryTax\Models\Profile;

/**
 * Class InstallationSummary.
 *
 * What an installation holds, for the starters' overview (`prytax:summary`, builds.md §6): its database (when it was
 * created, its size), its organizations, their ФОПs, the documents and backup archives each keeps, and each ФОП's
 * latest backup. It reads the database alone (the files' and archives' recorded sizes), so it works in Docker too.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Services\Installation
 */
final class InstallationSummary
{
    /**
     * Get the summary: the database first (its `created_at` is the first in the JSON, which `./start` relies on), then
     * the organizations with their ФОПs, then the totals. Dates are ISO 8601, in the app's time zone.
     *
     * @return array{
     *     database: array{driver: string, name: string, bytes: int|null, created_at: string|null},
     *     organizations: list<array{
     *         id: int, name: string, users: int,
     *         documents: array{count: int, bytes: int}, backups: array{count: int, bytes: int}, latest_backup_at: string|null,
     *         profiles: list<array{
     *             id: int, name: string, closed: bool,
     *             documents: array{count: int, bytes: int}, backups: array{count: int, bytes: int}, latest_backup_at: string|null,
     *         }>,
     *     }>,
     *     totals: array{
     *         organizations: int, profiles: int,
     *         documents: array{count: int, bytes: int}, backups: array{count: int, bytes: int}, latest_backup_at: string|null,
     *     },
     * }
     */
    public function make(): array
    {
        $documents = $this->documents();
        [$backups, $latest] = [$this->backups(), $this->latestBackups()];
        $profiles = Profile::query()
            ->withoutGlobalScope(Profile::ORGANIZATION_SCOPE)
            ->orderBy('full_name')
            ->get(['id', 'organization_id', 'full_name', 'closed_at'])
            ->groupBy('organization_id');

        $organizations = Organization::query()->withCount('users')->orderBy('id')->get()
            ->map(function (Organization $organization) use ($profiles, $documents, $backups, $latest) {
                $own = ($profiles[$organization->id] ?? collect())->map(fn (Profile $profile) => [
                    'id' => $profile->id,
                    'name' => $profile->short_name,
                    'closed' => $profile->closed_at !== null,
                    'documents' => $documents[$profile->id] ?? self::usage(),
                    'backups' => $backups[$profile->id] ?? self::usage(),
                    'latest_backup_at' => $latest[$profile->id] ?? null,
                ])->values();

                return [
                    'id' => $organization->id,
                    'name' => (string) $organization->name,
                    'users' => (int) $organization->users_count,
                    ...self::totalsOf($own),
                    'profiles' => $own->all(),
                ];
            })->values();

        return [
            'database' => $this->database(),
            'organizations' => $organizations->all(),
            'totals' => [
                'organizations' => $organizations->count(),
                'profiles' => $organizations->sum(fn (array $organization) => count($organization['profiles'])),
                ...self::totalsOf($organizations),
            ],
        ];
    }

    /**
     * Get the database: its driver, name, size (null when it can't be told) and when it was created.
     *
     * @return array{driver: string, name: string, bytes: int|null, created_at: string|null}
     */
    protected function database(): array
    {
        $connection = DB::connection();
        $name = (string) $connection->getDatabaseName();
        $bytes = match ($connection->getDriverName()) {
            'sqlite' => is_file($name) ? (int) filesize($name) + (is_file("$name-wal") ? (int) filesize("$name-wal") : 0) : null,
            'mysql', 'mariadb' => (int) $connection->scalar(
                'select coalesce(sum(data_length + index_length), 0) from information_schema.tables where table_schema = ?',
                [$name],
            ),
            default => null,
        };

        return [
            'driver' => $connection->getDriverName(),
            'name' => $name,
            'bytes' => $bytes,
            'created_at' => self::date(Schema::hasTable('installations') ? DB::table('installations')->min('created_at') : null),
        ];
    }

    /**
     * Get the documents each ФОП keeps, by its id: its invoices' files (generated and uploaded) and its signature.
     *
     * @return array<int, array{count: int, bytes: int}>
     */
    protected function documents(): array
    {
        $invoices = File::query()
            ->join('invoices', 'invoices.id', '=', 'files.fileable_id')
            ->where('files.fileable_type', (new Invoice)->getMorphClass())
            ->groupBy('invoices.profile_id')
            ->selectRaw('invoices.profile_id as profile_id, count(*) as count, coalesce(sum(files.size), 0) as bytes')
            ->toBase()
            ->get();
        $signatures = File::query()
            ->where('fileable_type', (new Profile)->getMorphClass())
            ->groupBy('fileable_id')
            ->selectRaw('fileable_id as profile_id, count(*) as count, coalesce(sum(size), 0) as bytes')
            ->toBase()
            ->get();
        $documents = [];

        foreach ([...$invoices, ...$signatures] as $row) {
            $usage = $documents[$row->profile_id] ?? self::usage();
            $documents[$row->profile_id] = self::usage($usage['count'] + (int) $row->count, $usage['bytes'] + (int) $row->bytes);
        }

        return $documents;
    }

    /**
     * Get the backup archives each ФОП keeps, by its id: the completed ones (a pruned one's file is gone).
     *
     * @return array<int, array{count: int, bytes: int}>
     */
    protected function backups(): array
    {
        return Backup::query()
            ->withoutGlobalScopes()
            ->where('status', BackupStatus::Completed)
            ->groupBy('profile_id')
            ->selectRaw('profile_id, count(*) as count, coalesce(sum(bytes), 0) as bytes')
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $row) => [(int) $row->profile_id => self::usage((int) $row->count, (int) $row->bytes)])
            ->all();
    }

    /**
     * Get each ФОП's latest backup, by its id: its newest completed archive but an export, as Резервні копії tells.
     *
     * @return array<int, string>
     */
    protected function latestBackups(): array
    {
        return Backup::query()
            ->withoutGlobalScopes()
            ->where('status', BackupStatus::Completed)
            ->where('tier', '!=', BackupKind::Export)
            ->groupBy('profile_id')
            ->selectRaw('profile_id, max(created_at) as latest')
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $row) => [(int) $row->profile_id => self::date($row->latest)])
            ->all();
    }

    /**
     * Get the totals of the items: their documents, backups and latest backup.
     *
     * @param Collection<int, array{documents: array{count: int, bytes: int}, backups: array{count: int, bytes: int}, latest_backup_at: string|null}> $items
     *
     * @return array{documents: array{count: int, bytes: int}, backups: array{count: int, bytes: int}, latest_backup_at: string|null}
     */
    protected static function totalsOf(Collection $items): array
    {
        return [
            'documents' => self::usage($items->sum('documents.count'), $items->sum('documents.bytes')),
            'backups' => self::usage($items->sum('backups.count'), $items->sum('backups.bytes')),
            // As instants: summer and winter time have other offsets
            'latest_backup_at' => $items->pluck('latest_backup_at')->filter()->sortBy(fn (string $date) => CarbonImmutable::parse($date)->getTimestamp())->last(),
        ];
    }

    /**
     * Get a usage: how many files, and their bytes.
     *
     * @param int $count
     * @param int $bytes
     *
     * @return array{count: int, bytes: int}
     */
    protected static function usage(int $count = 0, int $bytes = 0): array
    {
        return ['count' => $count, 'bytes' => $bytes];
    }

    /**
     * Get a database's date as ISO 8601, in the app's time zone, or null.
     *
     * @param mixed $value
     *
     * @return string|null
     */
    protected static function date(mixed $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse($value)->toIso8601String();
    }
}
