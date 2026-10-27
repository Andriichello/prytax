<?php

namespace PryTax\DataLifecycle\Backup\Internal;

use Carbon\CarbonImmutable;
use Closure;
use Core\Models\Organization;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PryTax\DataLifecycle\Backup\Enums\BackupKind;
use PryTax\DataLifecycle\Backup\Enums\BackupStatus;
use PryTax\DataLifecycle\Backup\Enums\RunStatus;
use PryTax\DataLifecycle\Backup\Events\BackupRunFinished;
use PryTax\DataLifecycle\Backup\Models\Backup;
use PryTax\DataLifecycle\Backup\Models\BackupRun;
use PryTax\DataLifecycle\Contracts\TenantRoot;
use Throwable;

/**
 * Class BackupRunner.
 *
 * Executes a run (backups.md §4.3). Every profile's data is written inside one consistent snapshot on the run's
 * connection, where nothing but reads happens; an unchanged profile (`skip_unchanged`, by its content hash) stops
 * there, before any document is fetched. After the snapshot is committed, the archives get their documents and are
 * uploaded and verified, and the outcome of every profile is recorded. A retry resumes the run: profiles, which have
 * an archive or were skipped, aren't done again. One profile's failure fails its backup only.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Backup\Internal
 */
final class BackupRunner
{
    /**
     * Milliseconds, for which a statement of the snapshot may run.
     *
     * @var int
     */
    public const int STATEMENT_TIMEOUT = 600000;

    /**
     * BackupRunner constructor.
     *
     * @param ArchiveWriter $writer
     * @param ArchiveStore $store
     * @param TenantRoot $root
     */
    public function __construct(
        private readonly ArchiveWriter $writer,
        private readonly ArchiveStore $store,
        private readonly TenantRoot $root,
    ) {}

    /**
     * Execute the run, or what is left of it.
     *
     * @param BackupRun $run
     *
     * @return void
     */
    public function run(BackupRun $run): void
    {
        if ($run->status->isFinished()) {
            return;
        }

        $done = $run->backups()->whereIn('status', [BackupStatus::Completed, BackupStatus::SkippedUnchanged])->pluck('profile_id')->all();
        $tenants = array_values(array_diff($this->tenantsOf($run), $done));
        $spec = $run->spec();
        $skipUnchanged = (bool) ($run->options['skip_unchanged'] ?? false);
        $previous = $skipUnchanged ? $this->previousHashes($tenants) : [];

        $run->forceFill(['status' => RunStatus::Running, 'started_at' => $run->started_at ?? now(), 'error' => null])->save();

        $organization = Organization::query()->findOrFail($run->organization_id);
        $context = ['run_id' => $run->id, 'organization' => ['id' => $organization->id, 'name' => $organization->name]];

        $db = $this->connection();
        $archives = [];
        $outcomes = [];

        $snapshotAt = $this->inSnapshot($db, function () use ($db, $tenants, $spec, $context, $previous, &$archives, &$outcomes) {
            $snapshotAt = $db->selectOne('SELECT UTC_TIMESTAMP() AS now')->now;
            $context['snapshot_at'] = str_replace(' ', 'T', $snapshotAt) . 'Z';

            foreach ($tenants as $tenant) {
                try {
                    $archive = $this->writer->writeData($tenant, $spec, $db, $context);
                } catch (Throwable $exception) {
                    report($exception);
                    $outcomes[$tenant] = ['status' => BackupStatus::Failed, 'error' => $exception->getMessage()];

                    continue;
                }

                if (isset($previous[$tenant]) && $previous[$tenant] === $archive->contentHash) {
                    $archive->discard();
                    $outcomes[$tenant] = ['status' => BackupStatus::SkippedUnchanged, 'content_hash' => $archive->contentHash];

                    continue;
                }

                $archives[$tenant] = $archive;
            }

            return $snapshotAt;
        });

        $run->snapshot_at ??= CarbonImmutable::parse($snapshotAt, 'UTC');

        foreach ($archives as $tenant => $archive) {
            $outcomes[$tenant] = $this->store($run, $tenant, $archive);
        }

        foreach ($outcomes as $tenant => $outcome) {
            $this->record($run, $tenant, $outcome);
        }

        $this->finish($run);
    }

    /**
     * Mark the run failed, when its job gave up.
     *
     * @param BackupRun $run
     * @param Throwable|null $exception
     *
     * @return void
     */
    public function fail(BackupRun $run, ?Throwable $exception): void
    {
        $run->forceFill([
            'status' => RunStatus::Failed,
            'finished_at' => now(),
            'error' => $exception ? Str::limit($exception->getMessage(), 1000) : null,
        ])->save();

        BackupRunFinished::dispatch($run->id, $run->organization_id);
    }

    /**
     * Get the tenants of the run: its own list, or every tenant of the organization, which isn't deleted.
     *
     * @param BackupRun $run
     *
     * @return array<int, int>
     */
    private function tenantsOf(BackupRun $run): array
    {
        $query = DB::table($this->root->table())->where($this->root->organizationColumn(), $run->organization_id)->orderBy('id');

        $run->profile_ids === null ? $query->whereNull('deleted_at') : $query->whereIn('id', $run->profile_ids);

        return $query->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Get the content hash of every tenant's latest archive.
     *
     * @param array<int, int> $tenants
     *
     * @return array<int, string>
     */
    private function previousHashes(array $tenants): array
    {
        $hashes = [];

        foreach ($tenants as $tenant) {
            $hash = Backup::query()->withoutGlobalScopes()
                ->where('profile_id', $tenant)
                ->where('status', BackupStatus::Completed)
                ->whereIn('tier', [BackupKind::Scheduled, BackupKind::Manual, BackupKind::Automatic])
                ->latest('created_at')
                ->value('content_hash');

            if ($hash !== null) {
                $hashes[$tenant] = $hash;
            }
        }

        return $hashes;
    }

    /**
     * Add the documents to a tenant's archive and upload it.
     *
     * @param BackupRun $run
     * @param int $tenant
     * @param DataArchive $archive
     *
     * @return array<string, mixed>
     */
    private function store(BackupRun $run, int $tenant, DataArchive $archive): array
    {
        $id = (string) Str::ulid();

        try {
            $file = $this->writer->addDocuments($archive);
            $path = $run->kind === BackupKind::Export
                ? "tmp/exports/$run->id/$id.zip"
                : sprintf('org/%d/profile/%d/%s/%s.zip', $run->organization_id, $tenant, now()->format('Y/m'), $id);

            $this->store->put($file->path, $path);

            return [
                'id' => $id,
                'status' => BackupStatus::Completed,
                'disk' => $this->store->diskName(),
                'object_path' => $path,
                'file_name' => $this->fileName($archive),
                'bytes' => $file->bytes,
                'sha256' => $file->sha256,
                'content_hash' => $archive->contentHash,
                'manifest' => $archive->manifest,
            ];
        } catch (Throwable $exception) {
            report($exception);

            return ['status' => BackupStatus::Failed, 'error' => $exception->getMessage()];
        } finally {
            $archive->discard();
        }
    }

    /**
     * Get the name, under which an archive is downloaded: `prytax_kovalenko-olena_p42_2026-10-27T0237Z.zip`.
     *
     * @param DataArchive $archive
     *
     * @return string
     */
    private function fileName(DataArchive $archive): string
    {
        $at = CarbonImmutable::parse((string) $archive->manifest['snapshot_at'])->utc();

        return "prytax_{$archive->folder}_" . $at->format('Y-m-d\THi\Z') . '.zip';
    }

    /**
     * Record a tenant's outcome in the run, replacing the one of an earlier attempt.
     *
     * @param BackupRun $run
     * @param int $tenant
     * @param array<string, mixed> $outcome
     *
     * @return void
     */
    private function record(BackupRun $run, int $tenant, array $outcome): void
    {
        $manifest = $outcome['manifest'] ?? null;
        $backup = Backup::query()->where('run_id', $run->id)->where('profile_id', $tenant)->first() ?? new Backup;

        if (!$backup->exists && isset($outcome['id'])) {
            $backup->id = $outcome['id'];
        }

        $backup->forceFill([
            'run_id' => $run->id,
            'organization_id' => $run->organization_id,
            'profile_id' => $tenant,
            'status' => $outcome['status'],
            'tier' => $run->kind,
            'disk' => $outcome['disk'] ?? null,
            'object_path' => $outcome['object_path'] ?? null,
            'file_name' => $outcome['file_name'] ?? null,
            'bytes' => $outcome['bytes'] ?? null,
            'sha256' => $outcome['sha256'] ?? null,
            'content_hash' => $outcome['content_hash'] ?? null,
            'row_counts' => $manifest ? array_column($manifest['files'], 'rows', 'path') : null,
            'manifest' => $manifest,
            'format_version' => $manifest['format_version'] ?? null,
            'registry_hash' => $manifest['registry_hash'] ?? null,
            'schema_version' => $manifest['schema_version'] ?? null,
            'include_documents' => $run->spec()->includesDocuments(),
            'snapshot_at' => $run->snapshot_at,
            'expires_at' => $this->expiryOf($run),
            'error' => isset($outcome['error']) ? Str::limit((string) $outcome['error'], 1000) : null,
        ])->save();
    }

    /**
     * Get when a backup of the run expires: automatic ones after 30 days, exports after a day, others never (the
     * policy's retention prunes them).
     *
     * @param BackupRun $run
     *
     * @return mixed
     */
    private function expiryOf(BackupRun $run): mixed
    {
        return match ($run->kind) {
            BackupKind::Automatic => now()->addDays((int) config('data-lifecycle.backups.automatic_days')),
            BackupKind::Export => now()->addHours((int) config('data-lifecycle.backups.temporary_hours')),
            default => null,
        };
    }

    /**
     * Write the run's manifest and its status, and tell its listeners.
     *
     * @param BackupRun $run
     *
     * @return void
     */
    private function finish(BackupRun $run): void
    {
        $backups = $run->backups()->orderBy('profile_id')->get();
        $failed = $backups->where('status', BackupStatus::Failed)->count();

        $status = match (true) {
            $failed === 0 => RunStatus::Completed,
            $failed === $backups->count() => RunStatus::Failed,
            default => RunStatus::Partial,
        };

        if ($run->kind !== BackupKind::Export) {
            $this->store->putContents("org/$run->organization_id/runs/$run->id/manifest.json", json_encode([
                'run_id' => $run->id,
                'organization_id' => $run->organization_id,
                'kind' => $run->kind->value,
                'snapshot_at' => $run->snapshot_at?->toIso8601ZuluString(),
                'profiles' => $backups->map(fn (Backup $backup) => [
                    'profile_id' => $backup->profile_id,
                    'backup_id' => $backup->id,
                    'status' => $backup->status->value,
                    'sha256' => $backup->sha256,
                    'bytes' => $backup->bytes,
                ])->values()->all(),
                'totals' => [
                    'bytes' => (int) $backups->sum('bytes'),
                    'completed' => $backups->where('status', BackupStatus::Completed)->count(),
                    'skipped' => $backups->where('status', BackupStatus::SkippedUnchanged)->count(),
                    'failed' => $failed,
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        $run->forceFill(['status' => $status, 'finished_at' => now()])->save();

        BackupRunFinished::dispatch($run->id, $run->organization_id);
    }

    /**
     * Get the connection of the snapshot.
     *
     * @return ConnectionInterface
     */
    private function connection(): ConnectionInterface
    {
        return DB::connection(config('data-lifecycle.connection'));
    }

    /**
     * Run the callback inside a consistent, read-only snapshot of the connection, with a time limit on its
     * statements. A connection inside a transaction already (a test's) reads inside that one.
     *
     * @template TResult
     *
     * @param ConnectionInterface $db
     * @param Closure(): TResult $callback
     *
     * @return TResult
     */
    private function inSnapshot(ConnectionInterface $db, Closure $callback): mixed
    {
        if ($db->transactionLevel() > 0) {
            return $callback();
        }

        $db->unprepared('SET SESSION MAX_EXECUTION_TIME = ' . self::STATEMENT_TIMEOUT);
        $db->unprepared('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $db->unprepared('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');

        try {
            return $callback();
        } finally {
            $db->unprepared('COMMIT');
            $db->unprepared('SET SESSION MAX_EXECUTION_TIME = DEFAULT');
        }
    }
}
