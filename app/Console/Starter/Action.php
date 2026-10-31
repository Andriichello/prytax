<?php

namespace PryTax\Console\Starter;

use Illuminate\Support\Facades\Process;
use Laravel\Sanctum\Sanctum;
use PryTax\Console\Commands\Start;
use Symfony\Component\Process\Process as SymfonyProcess;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\warning;

/**
 * Class Action.
 *
 * One of the starter's actions (`prytax:start {action}`), with what they share: running a command in the foreground,
 * offering a database's pending migrations and a fresh build of the SPA, refusing a port in use, stopping what runs,
 * serving PryTax behind a tunnel for a phone, and remembering the app started, which the menu offers again.
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
     * Run a command in the foreground, on the terminal when there is one (colours, prompts), and get whether it
     * succeeded. Ctrl+C stops the command, not the starter.
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
        $previous = pcntl_signal_get_handler(SIGINT);
        pcntl_async_signals(true);
        // A handler of its own, not SIG_IGN, which the command would inherit
        pcntl_signal(SIGINT, function () {});

        try {
            if ($this->start->isInteractive() && SymfonyProcess::isTtySupported()) {
                return $process->tty()->run($command)->successful();
            }

            return $process->run($command, fn (string $type, string $output) => $this->start->getOutput()->write($output))->successful();
        } finally {
            pcntl_signal(SIGINT, $previous);
        }
    }

    /**
     * Get a group of processes, which print to the starter's output.
     *
     * @return ProcessGroup
     */
    protected function group(): ProcessGroup
    {
        return new ProcessGroup(fn (string $line) => $this->start->getOutput()->writeln($line));
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
     * Stop a service of those `Listeners::services()` lists, and get whether it stopped: Docker's by `docker compose
     * down`, the desktop app by closing its Electron, as its window would be (which stops its PHP server and worker),
     * the others by their processes on its port.
     *
     * @param string $key
     *
     * @return bool
     */
    protected function stopService(string $key): bool
    {
        ['label' => $label, 'port' => $port] = $this->listeners->services()[$key];

        if ($key === 'docker') {
            $file = is_file(base_path('.env.docker')) ? base_path('.env.docker') : base_path('.env');

            return $this->run(['docker', 'compose', '--env-file', $file, '--profile', 'mysql', 'down']);
        }

        $pids = $key === 'desktop' ? $this->listeners->desktopProcesses() : [];
        $failed = $this->listeners->stop($pids ?: $this->listeners->listening()[$port] ?? []);

        if ($failed !== []) {
            warning("$label: couldn't stop " . implode(', ', $failed) . ' (another user\'s?).');
            note('sudo kill ' . implode(' ', $failed));

            return false;
        }

        info("Stopped: $label.");

        return true;
    }

    /**
     * What every app the starter runs is: one person's installation (builds.md §1.4, §6), whatever its settings say.
     *
     * @var array<string, string>
     */
    protected const array SINGLE_ORGANIZATION = ['PRYTAX_SINGLE_ORGANIZATION' => 'true'];

    /**
     * Get the variables, with which a server lets a tunnel's address sign in: the requests' own host stateful
     * (besides those configured), and ngrok's agent, on this machine, a proxy to trust, so its pages are https.
     *
     * @return array<string, string>
     */
    protected function tunnelled(): array
    {
        $stateful = array_filter((array) config('sanctum.stateful'));

        return [
            'SANCTUM_STATEFUL_DOMAINS' => implode(',', [...$stateful, Sanctum::$currentRequestHostPlaceholder]),
            'TRUSTED_PROXIES' => '127.0.0.1',
        ];
    }

    /**
     * Start PHP's server for a phone on 8012 in the group, on the database's data, for a tunnel to it: with files for
     * sessions and cache, and registration off, so the tunnel's address can't create organizations.
     *
     * @param ProcessGroup $group
     * @param Database $database
     *
     * @return void
     */
    protected function servePhone(ProcessGroup $group, Database $database): void
    {
        $env = $this->toolchain->env([
            ...$database->env,
            ...$this->tunnelled(),
            'SESSION_DRIVER' => 'file',
            'CACHE_STORE' => 'file',
            'QUEUE_CONNECTION' => 'sync',
            'PRYTAX_REGISTRATION' => 'false',
            'PHP_CLI_SERVER_WORKERS' => '4',
            ...self::SINGLE_ORGANIZATION,
        ]);
        // PHP's own server, not `artisan serve`, whose server process wouldn't see LARAVEL_STORAGE_PATH; it serves
        // from the working directory, so from public/
        $server = [
            $this->toolchain->php(), '-d', 'variables_order=EGPCS', '-S', '127.0.0.1:' . Listeners::PHONE_PORT,
            '-t', public_path(), base_path('vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php'),
        ];

        $group->start('phone', $server, $env, public_path());
    }

    /**
     * Remember that the choice's app started now (builds.md §6): the starters show it and offer it again, with when it
     * was set up here (anew with a new database) and when its database was created, as its overview tells.
     *
     * @param string $choice One of `StarterState::CHOICES`.
     * @param string|null $sqlite The SQLite file of the web app here on SQLite.
     * @param bool $newDatabase Whether its database was made by this run.
     *
     * @return void
     */
    protected function remember(string $choice, ?string $sqlite = null, bool $newDatabase = false): void
    {
        $state = app(StarterState::class);

        if ($newDatabase) {
            $state->forget($choice);
        }

        $summary = app(Summaries::class)->of($choice, $sqlite);
        $state->remember($choice, $sqlite, $summary['database']['created_at'] ?? null);
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
