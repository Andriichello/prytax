<?php

namespace PryTax\Console\Starter;

use PryTax\Console\Commands\Start;

use function Laravel\Prompts\spin;

/**
 * Class DoctorAction.
 *
 * What PryTax needs on this machine (`./prytax doctor`), a line a check (✓, ! or ✗) with what fixes it. It fails when
 * a check does, which only some ways of running PryTax would need when it's a warning.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Console\Starter
 */
class DoctorAction extends Action
{
    /**
     * Perform the action, and get its exit code.
     *
     * @return int
     */
    protected function handle(): int
    {
        $checks = spin(fn () => $this->doctor->all(), 'Checking…');
        $output = $this->start->getOutput();

        foreach ($checks as $check) {
            $mark = match ($check->status) {
                Check::OK => '<fg=green>✓</>',
                Check::WARN => '<fg=yellow>!</>',
                default => '<fg=red>✗</>',
            };

            $output->writeln(sprintf(' %s <options=bold>%s</> — %s', $mark, $check->name, $check->detail));

            if ($check->hint !== null) {
                $output->writeln("   <fg=gray>↳ $check->hint</>");
            }
        }

        return collect($checks)->contains(fn (Check $check) => $check->failed()) ? Start::FAILURE : Start::SUCCESS;
    }
}
