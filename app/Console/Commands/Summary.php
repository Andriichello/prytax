<?php

namespace PryTax\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use PryTax\Console\Starter\SummaryTable;
use PryTax\Services\Installation\InstallationSummary;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class Summary.
 *
 * An installation's overview (builds.md §6): its database, its organizations and their ФОПs, the documents and backup
 * archives each keeps, and each one's latest backup. The starters show it: `./prytax` reads it as JSON, `./start`
 * prints it from Docker's container and takes the database's `created_at` from the JSON, the first one in it.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Console\Commands
 */
#[Signature('prytax:summary {--json : The summary as JSON, on one line}')]
#[Description('Show the organizations, their ФОПs, the storage they use and their latest backups')]
class Summary extends Command
{
    /**
     * Execute the console command.
     *
     * @param InstallationSummary $summary
     *
     * @return int
     */
    public function handle(InstallationSummary $summary): int
    {
        $data = $summary->make();

        if ($this->option('json')) {
            $this->output->writeln(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);

            return self::SUCCESS;
        }

        SummaryTable::render($data, $this->output);

        return self::SUCCESS;
    }
}
