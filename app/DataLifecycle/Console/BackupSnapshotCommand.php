<?php

namespace PryTax\DataLifecycle\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PryTax\DataLifecycle\Contracts\TableRegistry;
use PryTax\DataLifecycle\Registry\ColumnSnapshot;
use PryTax\DataLifecycle\Registry\SchemaInspector;

/**
 * Class BackupSnapshotCommand.
 *
 * Writes the column snapshot of the registry (backups.md §4.2), after every column of a migration is reviewed. A
 * breaking change (a removed table or column, a changed treatment) is refused, until the registry's format version
 * is bumped, with an upcaster of the older archives.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Console
 */
class BackupSnapshotCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'prytax:backup:snapshot';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Write the column snapshot of the backup registry, once its changes are reviewed';

    /**
     * Execute the console command.
     *
     * @param TableRegistry $registry
     *
     * @return int
     */
    public function handle(TableRegistry $registry): int
    {
        $path = (string) config('data-lifecycle.snapshot_path');
        $current = ColumnSnapshot::of($registry, new SchemaInspector(DB::connection()));
        $committed = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        if (is_array($committed)) {
            $diff = ColumnSnapshot::diff($committed, $current);

            if ($diff['breaking'] !== [] && ($committed['format_version'] ?? null) === $current['format_version']) {
                $this->error('Breaking changes need a new format version of the registry (and an upcaster of the older archives):');
                $this->line(implode(PHP_EOL, array_map(fn (string $change) => "  $change", $diff['breaking'])));

                return self::FAILURE;
            }

            foreach ([...$diff['breaking'], ...$diff['additive']] as $change) {
                $this->line("  $change");
            }
        }

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents($path, json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        $this->info("The snapshot is written to $path.");

        return self::SUCCESS;
    }
}
