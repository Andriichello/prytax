<?php

namespace PryTax\DataLifecycle\Console;

use Illuminate\Console\Command;
use PryTax\DataLifecycle\Retention\Enums\RetentionStatus;
use PryTax\DataLifecycle\Retention\Models\RetentionJob;

/**
 * Class RetentionRetryCommand.
 *
 * Puts a failed retention job back to pending, so the next execution runs it again (backups.md §9.6).
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Console
 */
class RetentionRetryCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'prytax:retention:retry {job : Id of the failed job}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Put a failed retention job back to pending';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $job = RetentionJob::query()->findOrFail($this->argument('job'));

        if ($job->status !== RetentionStatus::Failed) {
            $this->error("The job is {$job->status->value}, not failed.");

            return self::FAILURE;
        }

        $job->forceFill(['status' => RetentionStatus::Pending, 'error' => null, 'started_at' => null, 'finished_at' => null])->save();
        $job->run->refreshStatus();
        $this->info('The job is pending again.');

        return self::SUCCESS;
    }
}
