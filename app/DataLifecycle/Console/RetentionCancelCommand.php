<?php

namespace PryTax\DataLifecycle\Console;

use Illuminate\Console\Command;
use LogicException;
use PryTax\DataLifecycle\Retention\Models\RetentionRun;
use PryTax\DataLifecycle\Retention\RetentionRuns;

/**
 * Class RetentionCancelCommand.
 *
 * Cancels a deletion run during its grace, which reverses the lock of its subject (backups.md §9.6).
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Console
 */
class RetentionCancelCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'prytax:retention:cancel {run : Id of the run}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Cancel a deletion run during its grace';

    /**
     * Execute the console command.
     *
     * @param RetentionRuns $runs
     *
     * @return int
     */
    public function handle(RetentionRuns $runs): int
    {
        try {
            $runs->cancel(RetentionRun::query()->findOrFail($this->argument('run')));
        } catch (LogicException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('The run is cancelled.');

        return self::SUCCESS;
    }
}
