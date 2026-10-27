<?php

namespace PryTax\DataLifecycle\Console;

use Illuminate\Console\Command;
use PryTax\DataLifecycle\Retention\RetentionRuns;

/**
 * Class RetentionRollingCommand.
 *
 * What the scheduler runs daily (backups.md §9.5): plans the run of the rolling cleanup, due now.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Console
 */
class RetentionRollingCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'prytax:retention:rolling';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Plan the daily run of the rolling cleanup';

    /**
     * Execute the console command.
     *
     * @param RetentionRuns $runs
     *
     * @return int
     */
    public function handle(RetentionRuns $runs): int
    {
        $run = $runs->plan('global', null, null, 'rolling', ['technical'], 0);
        $this->info("Planned the run $run->id.");

        return self::SUCCESS;
    }
}
