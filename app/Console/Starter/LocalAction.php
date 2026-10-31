<?php

namespace PryTax\Console\Starter;

use PryTax\Console\Commands\Start;

/**
 * Class LocalAction.
 *
 * The local app (`./prytax local`, builds.md §6): `./start` in the foreground, which runs your own PryTax in Docker
 * apart from development (its own settings, compose project, SQLite file and port), with its menu.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Console\Starter
 */
class LocalAction extends Action
{
    /**
     * Perform the action, and get its exit code.
     *
     * @return int
     */
    protected function handle(): int
    {
        return $this->run([base_path('start')]) ? Start::SUCCESS : Start::FAILURE;
    }
}
