<?php

namespace PryTax\DataLifecycle\Console;

use Illuminate\Console\Command;
use PryTax\DataLifecycle\Backup\ArchiveImports;
use PryTax\DataLifecycle\Backup\ImportException;

/**
 * Class BackupImportCommand.
 *
 * Restores the tenants of an archive (a tenant's, or every one of a run's bundle) into an organization as new tenants,
 * never in place, all of them or none (backups.md §5.1): ops' tool of phase 1. With `--dry-run` it imports, verifies
 * and rolls back, reporting what it would write.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Console
 */
class BackupImportCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'prytax:backup:import
        {archive : Local path of the archive (.zip)}
        {--organization= : Id of the organization, into which it is imported}
        {--dry-run : Import, verify and roll back}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import a backup archive into an organization as a new ФОП';

    /**
     * Execute the console command.
     *
     * @param ArchiveImporter $importer
     *
     * @return int
     */
    public function handle(ArchiveImports $imports): int
    {
        $organizationId = (int) $this->option('organization');

        if ($organizationId <= 0) {
            $this->error('Give the organization: --organization=ID.');

            return self::INVALID;
        }

        try {
            $results = $imports->importAll((string) $this->argument('archive'), $organizationId, (bool) $this->option('dry-run'));
        } catch (ImportException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        foreach ($results as $result) {
            $this->line(json_encode($result->tenant, JSON_UNESCAPED_UNICODE) ?: '');
            $this->table(['Table', 'Rows'], collect($result->rows)->map(fn (int $rows, string $table) => [$table, $rows])->values()->all());
            $this->line("Documents: $result->documents" . ($result->skippedFiles ? ", files rows without their document left out: $result->skippedFiles" : ''));
            $this->line('Checks: ' . json_encode($result->checks, JSON_UNESCAPED_UNICODE));

            $result->dryRun
                ? $this->info('Dry run: everything was verified and rolled back.')
                : $this->info("Imported as the new tenant $result->tenantId.");
        }

        return self::SUCCESS;
    }
}
