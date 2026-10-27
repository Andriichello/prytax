<?php

namespace PryTax\DataLifecycle\Backup\Internal;

use Core\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PryTax\DataLifecycle\Backup\Enums\BackupKind;
use PryTax\DataLifecycle\Backup\Enums\BackupStatus;
use PryTax\DataLifecycle\Backup\Models\Backup;
use RuntimeException;
use Throwable;

/**
 * Class BackupVerifier.
 *
 * Proves that an archive restores (backups.md §5.2): it is imported in a dry run into a scratch organization, both
 * rolled back, which verifies its rows and checks as a real import would. A pass is recorded in `verified_at`; a
 * failure is reported to ops.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Backup\Internal
 */
final class BackupVerifier
{
    /**
     * BackupVerifier constructor.
     *
     * @param ArchiveStore $store
     * @param ArchiveImporter $importer
     */
    public function __construct(
        private readonly ArchiveStore $store,
        private readonly ArchiveImporter $importer,
    ) {}

    /**
     * Verify a random archive of the last month, which isn't verified yet, or the given one.
     *
     * @param Backup|null $backup
     *
     * @return Backup|null The archive verified, or null when there was none.
     * @throws Throwable When it doesn't restore.
     */
    public function verify(?Backup $backup = null): ?Backup
    {
        $backup ??= Backup::query()->withoutGlobalScopes()
            ->where('status', BackupStatus::Completed)
            ->whereIn('tier', [BackupKind::Scheduled, BackupKind::Manual, BackupKind::Automatic])
            ->whereNull('verified_at')
            ->where('created_at', '>=', now()->subMonth())
            ->inRandomOrder()
            ->first();

        if ($backup === null) {
            return null;
        }

        $local = $this->store->fetch((string) $backup->disk, (string) $backup->object_path);

        try {
            if (hash_file('sha256', $local) !== $backup->sha256) {
                throw new RuntimeException("The archive of backup $backup->id differs from its checksum.");
            }

            DB::transaction(function () use ($local) {
                $scratch = Organization::query()->create(['slug' => 'backup-verification-' . Str::lower(Str::random(12)), 'name' => 'Backup verification']);
                $this->importer->import($local, $scratch->id, dryRun: true);

                throw new DryRunRollback;
            });
        } catch (DryRunRollback) {
            $backup->forceFill(['verified_at' => now()])->saveQuietly();
        } finally {
            unlink($local);
        }

        return $backup;
    }
}
