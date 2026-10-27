<?php

namespace PryTax\DataLifecycle\Console;

use Illuminate\Console\Command;
use PryTax\DataLifecycle\Backup\Internal\Pruner;

/**
 * Class BackupPruneCommand.
 *
 * What the scheduler runs daily (backups.md §4.3): prunes the archives, which the organizations' retention doesn't
 * keep, and the expired bundles and downloads.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Console
 */
class BackupPruneCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'prytax:backup:prune';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Prune the backups, which retention doesn\'t keep';

    /**
     * Execute the console command.
     *
     * @param Pruner $pruner
     *
     * @return int
     */
    public function handle(Pruner $pruner): int
    {
        $this->info('Pruned ' . $pruner->prune() . ' backups.');

        return self::SUCCESS;
    }
}
