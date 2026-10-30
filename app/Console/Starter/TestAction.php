<?php

namespace PryTax\Console\Starter;

use PryTax\Console\Commands\Start;

use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\table;

/**
 * Class TestAction.
 *
 * PryTax's tests (`./prytax test`, `--filter` to narrow them): PHP's on MySQL (`prytax_test`) or on SQLite in memory,
 * the SPA's and its types, one after another, as two PHP runs at once would share their fake disks.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Console\Starter
 */
class TestAction extends Action
{
    /**
     * Perform the action, and get its exit code.
     *
     * @return int
     */
    protected function handle(): int
    {
        $mysql = config('database.default') === 'mysql' && $this->doctor->mysql()->status === Check::OK;
        $suites = multiselect('Which tests?', [
            'php-mysql' => 'PHP on MySQL (prytax_test)',
            'php-sqlite' => 'PHP on SQLite, in memory',
            'spa' => 'SPA (Vitest)',
            'types' => 'SPA types (vue-tsc)',
        ], default: [$mysql ? 'php-mysql' : 'php-sqlite', 'spa', 'types'], required: true);

        $filter = $this->start->option('filter');
        $php = [$this->toolchain->php(), 'artisan', 'test', '--compact', ...($filter ? ["--filter=$filter"] : [])];

        // A cached configuration would keep the tests off their own database
        if (array_intersect($suites, ['php-mysql', 'php-sqlite']) !== [] && app()->configurationIsCached()) {
            $this->artisan(['config:clear']);
        }

        $results = [];

        foreach ($suites as $suite) {
            $results[$suite] = match ($suite) {
                'php-mysql' => $this->run($php),
                'php-sqlite' => $this->run($php, ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:']),
                'spa' => $this->run(['npm', 'test', ...($filter ? ['--', $filter] : [])]),
                'types' => $this->run(['npm', 'run', 'typecheck']),
            };
        }

        table(['Tests', 'Result'], collect($results)->map(fn (bool $passed, string $suite) => [$suite, $passed ? 'passed' : 'FAILED'])->values()->all());

        return in_array(false, $results, true) ? Start::FAILURE : Start::SUCCESS;
    }
}
