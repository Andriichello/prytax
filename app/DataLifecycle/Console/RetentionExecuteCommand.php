<?php

namespace PryTax\DataLifecycle\Console;

use Illuminate\Console\Command;
use PryTax\DataLifecycle\Retention\Internal\DueJobs;

/**
 * Class RetentionExecuteCommand.
 *
 * What the scheduler runs every 5 minutes (backups.md §9.5, §9.6): dispatches the retention jobs, which are due.
 * Safe to run by hand.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Console
 */
class RetentionExecuteCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'prytax:retention:execute';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dispatch the retention jobs, which are due';

    /**
     * Execute the console command.
     *
     * @param DueJobs $jobs
     *
     * @return int
     */
    public function handle(DueJobs $jobs): int
    {
        $this->info('Dispatched ' . $jobs->dispatch() . ' retention jobs.');

        return self::SUCCESS;
    }
}
