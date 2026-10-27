<?php

namespace PryTax\DataLifecycle\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PryTax\DataLifecycle\Backup\Internal\BackupVerifier;
use Throwable;

/**
 * Class BackupVerifyCommand.
 *
 * What the scheduler runs weekly (backups.md §5.2): imports a random recent archive in a dry run, and reports a
 * failure to ops.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Console
 */
class BackupVerifyCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'prytax:backup:verify';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verify that a random recent backup restores, in a dry run';

    /**
     * Execute the console command.
     *
     * @param BackupVerifier $verifier
     *
     * @return int
     */
    public function handle(BackupVerifier $verifier): int
    {
        try {
            $backup = $verifier->verify();
        } catch (Throwable $exception) {
            report($exception);
            Log::error('A backup failed its verification.', ['error' => $exception->getMessage()]);
            $this->error("The backup doesn't restore: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $this->info($backup ? "The backup $backup->id restores." : 'No backup to verify.');

        return self::SUCCESS;
    }
}
