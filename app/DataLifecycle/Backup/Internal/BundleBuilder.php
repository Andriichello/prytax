<?php

namespace PryTax\DataLifecycle\Backup\Internal;

use Illuminate\Support\Str;
use PryTax\DataLifecycle\Backup\Enums\BackupKind;
use PryTax\DataLifecycle\Backup\Enums\BackupStatus;
use PryTax\DataLifecycle\Backup\Models\Backup;
use PryTax\DataLifecycle\Backup\Models\BackupRun;
use RuntimeException;
use ZipArchive;

/**
 * Class BundleBuilder.
 *
 * Builds the organization's bundle of a run on demand (backups.md §2.4, §4.5): a zip with a folder per profile, each
 * holding that profile's archive unchanged, so any folder can be copied out on its own, and a manifest of the run.
 * A profile, which the run skipped as unchanged, is there with its latest archive before, labelled with its own
 * snapshot. Nothing is stored twice: the bundle is built of the stored archives with ZipArchive on the worker's disk,
 * one profile at a time, and kept a day.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Backup\Internal
 */
final class BundleBuilder
{
    /**
     * BundleBuilder constructor.
     *
     * @param ArchiveStore $store
     */
    public function __construct(private readonly ArchiveStore $store) {}

    /**
     * Build the run's bundle, upload it, and record it on the run.
     *
     * @param BackupRun $run
     *
     * @return void
     */
    public function build(BackupRun $run): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'pbb');
        $profiles = [];

        try {
            foreach ($this->archivesOf($run) as $backup) {
                $folder = $this->folderOf($backup);
                $this->append($path, $backup, "profiles/$folder/");
                $profiles[] = [
                    'profile_id' => $backup->profile_id,
                    'folder' => "profiles/$folder",
                    'backup_id' => $backup->id,
                    'snapshot_at' => $backup->snapshot_at?->toIso8601ZuluString(),
                    'sha256' => $backup->sha256,
                    'bytes' => $backup->bytes,
                ];
            }

            $zip = $this->open($path, $profiles === [] ? ZipArchive::OVERWRITE : 0);
            $zip->addFromString('manifest.json', json_encode([
                'format' => ArchiveWriter::FORMAT . '-bundle',
                'run_id' => $run->id,
                'organization_id' => $run->organization_id,
                'snapshot_at' => $run->snapshot_at?->toIso8601ZuluString(),
                'profiles' => $profiles,
                'totals' => ['profiles' => count($profiles), 'bytes' => array_sum(array_column($profiles, 'bytes'))],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $zip->close();

            $object = "tmp/bundles/$run->id/" . Str::random(32) . '.zip';
            $this->store->put($path, $object);

            $run->forceFill([
                'bundle_status' => 'ready',
                'bundle_disk' => $this->store->diskName(),
                'bundle_path' => $object,
                'bundle_expires_at' => now()->addHours((int) config('data-lifecycle.backups.temporary_hours')),
            ])->save();
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * Get the name, under which a run's bundle is downloaded: `prytax_org-<slug>_run-<id>_2026-10-27T0237Z.zip`.
     *
     * @param BackupRun $run
     * @param string $slug
     *
     * @return string
     */
    public static function fileName(BackupRun $run, string $slug): string
    {
        return "prytax_org-{$slug}_run-{$run->id}_" . ($run->snapshot_at ?? $run->created_at)->utc()->format('Y-m-d\THi\Z') . '.zip';
    }

    /**
     * Get the archives of the run's profiles: each one's of the run, or its latest before, when the run skipped it.
     *
     * @param BackupRun $run
     *
     * @return array<int, Backup>
     */
    private function archivesOf(BackupRun $run): array
    {
        $archives = [];

        foreach ($run->backups()->withoutGlobalScopes()->orderBy('profile_id')->get() as $backup) {
            if ($backup->status === BackupStatus::SkippedUnchanged) {
                $backup = Backup::query()->withoutGlobalScopes()
                    ->where('profile_id', $backup->profile_id)
                    ->where('status', BackupStatus::Completed)
                    ->whereIn('tier', [BackupKind::Scheduled, BackupKind::Manual, BackupKind::Automatic])
                    ->where('created_at', '<=', $backup->created_at)
                    ->latest('created_at')
                    ->first();
            }

            if ($backup !== null && $backup->hasArchive()) {
                $archives[] = $backup;
            }
        }

        return $archives;
    }

    /**
     * Copy every entry of a profile's archive into the bundle under the folder, keeping its compression.
     *
     * @param string $path
     * @param Backup $backup
     * @param string $folder
     *
     * @return void
     */
    private function append(string $path, Backup $backup, string $folder): void
    {
        $local = $this->store->fetch((string) $backup->disk, (string) $backup->object_path);
        $directory = $local . '.d';
        mkdir($directory);

        try {
            $from = $this->open($local, ZipArchive::RDONLY);
            clearstatcache(true, $path);
            $to = $this->open($path, filesize($path) === 0 ? ZipArchive::OVERWRITE : 0);

            for ($i = 0; $i < $from->numFiles; $i++) {
                $stat = $from->statIndex($i);
                $entry = $directory . '/' . $i;
                file_put_contents($entry, $from->getFromIndex($i));

                $to->addFile($entry, $folder . $stat['name']);
                $to->setCompressionName($folder . $stat['name'], $stat['comp_method'] === ZipArchive::CM_STORE ? ZipArchive::CM_STORE : ZipArchive::CM_DEFLATE);
            }

            $from->close();

            // Written now, one profile at a time, so its entries' files can go
            if (!$to->close()) {
                throw new RuntimeException("The bundle $path couldn't be written.");
            }
        } finally {
            array_map('unlink', glob("$directory/*") ?: []);
            rmdir($directory);
            unlink($local);
        }
    }

    /**
     * Get the profile's folder in the bundle, from the name of its archive (`kovalenko-olena_p42`).
     *
     * @param Backup $backup
     *
     * @return string
     */
    private function folderOf(Backup $backup): string
    {
        return preg_match('/^prytax_(.+)_\d{4}-\d{2}-\d{2}T\d{4}Z\.zip$/', (string) $backup->file_name, $match)
            ? $match[1]
            : "p$backup->profile_id";
    }

    /**
     * Open a zip.
     *
     * @param string $path
     * @param int $flags
     *
     * @return ZipArchive
     */
    private function open(string $path, int $flags): ZipArchive
    {
        $zip = new ZipArchive;
        $result = $zip->open($path, $flags);

        if ($result !== true) {
            throw new RuntimeException("The archive $path couldn't be opened ($result).");
        }

        return $zip;
    }
}
