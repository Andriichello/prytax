<?php

namespace PryTax\DataLifecycle\Console;

use Illuminate\Console\Command;
use PryTax\DataLifecycle\Retention\Models\RetentionJob;
use PryTax\DataLifecycle\Retention\Models\RetentionRun;

/**
 * Class RetentionStatusCommand.
 *
 * Shows the deletion runs (the latest, or one with its jobs): what is due when, done, failed and why (backups.md §9.6).
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Console
 */
class RetentionStatusCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'prytax:retention:status {run? : Id of a run, whose jobs to show}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Show the deletion runs, or the jobs of one';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $id = $this->argument('run');

        if ($id === null) {
            $this->table(['Run', 'Subject', 'Trigger', 'Status', 'Created'], RetentionRun::query()->latest('created_at')->limit(30)->get()
                ->map(fn (RetentionRun $run) => [$run->id, "$run->subject_type $run->subject_id", $run->trigger, $run->status->value, $run->created_at])
                ->all());

            return self::SUCCESS;
        }

        $run = RetentionRun::query()->findOrFail($id);
        $this->line("$run->subject_type $run->subject_id · $run->trigger · {$run->status->value}");
        $this->table(['Job', 'Family', 'Due', 'Status', 'Deleted', 'Anonymized', 'Objects', 'Error'], $run->jobs()->get()
            ->map(fn (RetentionJob $job) => [
                $job->id, $job->family, $job->due_at, $job->status->value,
                $job->records_deleted, $job->records_anonymized, $job->objects_deleted, $job->error,
            ])->all());

        return self::SUCCESS;
    }
}
