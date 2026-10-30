<?php

namespace PryTax\Console\Starter;

use Illuminate\Support\Facades\Process;
use PryTax\Console\Commands\Start;
use Symfony\Component\Process\Process as SymfonyProcess;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;

/**
 * Class Action.
 *
 * One of the starter's actions (`prytax:start {action}`), with what they share: running a command in the foreground,
 * offering a database's pending migrations and a fresh build of the SPA, and refusing a port in use.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Console\Starter
 */
abstract class Action
{
    /**
     * The starter's command, whose options and output the action uses.
     *
     * @var Start
     */
    protected Start $start;

    /**
     * Action constructor.
     *
     * @param Toolchain $toolchain
     * @param Listeners $listeners
     * @param Doctor $doctor
     */
    public function __construct(protected Toolchain $toolchain, protected Listeners $listeners, protected Doctor $doctor) {}

    /**
     * Perform the action for the starter's command, and get its exit code.
     *
     * @param Start $start
     *
     * @return int
     */
    public function __invoke(Start $start): int
    {
        $this->start = $start;

        return $this->handle();
    }

    /**
     * Perform the action, and get its exit code.
     *
     * @return int
     */
    abstract protected function handle(): int;

    /**
     * Run a command in the foreground, on the terminal when there is one (colours, prompts, Ctrl+C), and get whether
     * it succeeded.
     *
     * @param list<string> $command
     * @param array<string, string> $env
     * @param string|null $path
     *
     * @return bool
     */
    protected function run(array $command, array $env = [], ?string $path = null): bool
    {
        $process = Process::forever()->path($path ?? base_path())->env($this->toolchain->env($env));

        if ($this->start->isInteractive() && SymfonyProcess::isTtySupported()) {
            return $process->tty()->run($command)->successful();
        }

        return $process->run($command, fn (string $type, string $output) => $this->start->getOutput()->write($output))->successful();
    }

    /**
     * Run an artisan command in the foreground, and get whether it succeeded.
     *
     * @param list<string> $arguments
     * @param array<string, string> $env
     *
     * @return bool
     */
    protected function artisan(array $arguments, array $env = []): bool
    {
        return $this->run([$this->toolchain->php(), 'artisan', ...$arguments], $env);
    }

    /**
     * Refuse a port, which something listens on already.
     *
     * @param int $port
     * @param string $what
     *
     * @return bool Whether the port is free.
     */
    protected function free(int $port, string $what): bool
    {
        if (!$this->listeners->isListening($port)) {
            return true;
        }

        error("$what needs port $port, which is in use.");
        note('./prytax status shows what runs, and stops it.');

        return false;
    }

    /**
     * Bring a database up to date before it's used: make a new SQLite file (with the demo data, if wanted), or offer
     * its pending migrations, after a copy of a SQLite file. Get false when it can't be used.
     *
     * @param Database $database
     *
     * @return bool
     */
    protected function migrate(Database $database): bool
    {
        if ($database->isNew()) {
            info("Making {$database->label}…");
            @mkdir(dirname((string) $database->file), 0755, true);
            touch((string) $database->file);

            if (!$this->artisan(['migrate', '--force', '--seed'], $database->env)) {
                return false;
            }

            return !confirm('Add the demo data? (Практика Коваленко: sign in as olena@example.com / password)')
                || $this->artisan(['db:seed', '--class=Database\Seeders\DemoSeeder', '--force'], $database->env);
        }

        $pending = $database->pending($this->toolchain);

        if ($pending === null) {
            error(ucfirst($database->label) . ' doesn\'t answer.');
            note($database->file === null ? 'Start MySQL (sudo systemctl start mysql), or choose SQLite.' : 'See ./prytax doctor.');

            return false;
        }

        if ($pending === []) {
            return true;
        }

        note(count($pending) . " migrations haven't run on $database->label: " . implode(', ', $pending));
        $copied = $database->file === null ? '' : ' (a copy of the file is kept beside it)';

        if (!confirm("Run them now$copied?")) {
            return true;
        }

        if ($copy = $database->backup('migrate')) {
            info("Copied it to $copy.");
        }

        // The database seeder only syncs the roles, which new migrations may give new permissions
        return $this->artisan(['migrate', '--force'], $database->env) && $this->artisan(['db:seed', '--force'], $database->env);
    }

    /**
     * Offer to build the SPA when its build is missing or older than its sources, and get false when it must be built
     * but couldn't be.
     *
     * @return bool
     */
    protected function ensureBuild(): bool
    {
        $state = $this->doctor->buildState();

        if ($state === 'fresh') {
            return true;
        }

        $question = $state === 'missing' ? 'The SPA isn\'t built. Build it now?' : 'The SPA\'s build is older than its sources. Build it now?';

        if (!confirm($question)) {
            return $state === 'stale';
        }

        return $this->run(['npm', 'run', 'build']);
    }
}
