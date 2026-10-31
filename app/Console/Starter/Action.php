<?php

namespace PryTax\Console\Starter;

use Closure;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Prompt;
use Laravel\Sanctum\Sanctum;
use PryTax\Console\Commands\Start;
use Symfony\Component\Process\Process as SymfonyProcess;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\text;
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
     * The exit code of an action, which went back from its first question to the menu.
     *
     * @var int
     */
    public const int WENT_BACK = 3;

    /**
     * An answer, which goes back to the question asked before it.
     *
     * @var string
     */
    public const string BACK = "\0back";

    /**
     * An answer, which ends the questions: Quit, or a failure, which was told.
     *
     * @var string
     */
    public const string STOP = "\0stop";

    /**
     * Whether the question being asked can go back: to an earlier question, or to the menu.
     *
     * @var bool
     */
    protected bool $backable = false;

    /**
     * Whether the question being answered asked the person, rather than answering itself.
     *
     * @var bool
     */
    protected bool $prompted = false;

    /**
     * How the questions ended, when they did: `back`, `quit` or `failed`.
     *
     * @var string|null
     */
    protected ?string $end = null;

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
     * Get whether the starter reaches the file, and say why not: its container sees the checkout alone.
     *
     * @param string $path
     *
     * @return bool
     */
    protected function reachable(string $path): bool
    {
        if (!$this->toolchain->inContainer() || str_starts_with($path, base_path() . '/')) {
            return true;
        }

        error("$path is outside the checkout, which is all the starter's container sees: choose a file in it, such as database/database.sqlite.");

        return false;
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
     * Ask the questions in turn: each a closure of the answers so far, which asks (`choose()`, `decide()`, `typed()`)
     * or answers itself (an option given, a question, which doesn't apply), and returns its answer, `BACK` or `STOP`.
     * Back goes to the question asked before it, the first one's to the menu; Stop ends them (Quit, or a failure, which
     * was told). Get the answers, or null, when they ended (`ended()` tells how).
     *
     * @param array<string, Closure(array<string, mixed>): mixed> $questions
     *
     * @return array<string, mixed>|null
     */
    protected function ask(array $questions): ?array
    {
        $keys = array_keys($questions);
        [$asked, $answers, $this->end] = [[], [], null];

        for ($index = 0; $index < count($keys);) {
            $this->backable = $asked !== [] || $this->start->isInMenu();
            $this->prompted = false;
            $answer = $questions[$keys[$index]]($answers);

            if ($answer === self::BACK) {
                if ($asked === []) {
                    $this->end = 'back';

                    return null;
                }

                $index = array_pop($asked);

                foreach (array_slice($keys, $index) as $key) {
                    unset($answers[$key]);
                }

                continue;
            }

            if ($answer === self::STOP) {
                $this->end = $this->start->isQuitting() ? 'quit' : 'failed';

                return null;
            }

            $answers[$keys[$index]] = $answer;

            if ($this->prompted) {
                $asked[] = $index;
            }

            $index++;
        }

        return $answers;
    }

    /**
     * Get the exit code of an action, whose questions ended: back to the menu, quit, or failed.
     *
     * @return int
     */
    protected function ended(): int
    {
        return match ($this->end) {
            'back' => self::WENT_BACK,
            'quit' => Start::SUCCESS,
            default => Start::FAILURE,
        };
    }

    /**
     * Ask which of the options, with Back, when there's a question or a menu to go back to, and Quit; get the option's
     * key, `BACK`, or `STOP` for Quit.
     *
     * @param string $label
     * @param array<string, string> $options
     * @param string|null $default
     * @param string $hint
     *
     * @return string
     */
    protected function choose(string $label, array $options, ?string $default = null, string $hint = ''): string
    {
        $this->prompted = true;

        return match ($choice = $this->start->pick($label, $options, $this->backable, $default, $hint)) {
            'back' => self::BACK,
            'quit' => self::STOP,
            default => $choice,
        };
    }

    /**
     * Ask yes or no, as two options with their own words, with Back and Quit; get true or false, `BACK` or `STOP`.
     *
     * @param string $label
     * @param string $yes
     * @param string $no
     * @param bool $default
     *
     * @return bool|string
     */
    protected function decide(string $label, string $yes, string $no, bool $default = true): bool|string
    {
        $choice = $this->choose($label, ['yes' => $yes, 'no' => $no], $default ? 'yes' : 'no');

        return in_array($choice, [self::BACK, self::STOP], true) ? $choice : $choice === 'yes';
    }

    /**
     * Ask for a typed answer, which Esc leaves for the question before it, when there's one to go back to; get it, or
     * `BACK`.
     *
     * @param string $label
     * @param string $default
     *
     * @return string
     */
    protected function typed(string $label, string $default = ''): string
    {
        $this->prompted = true;

        if ($this->backable) {
            Prompt::cancelUsing(fn () => self::BACK);
        }

        try {
            return (string) text($label, default: $default, required: true, hint: $this->backable ? 'Esc goes back.' : '');
        } finally {
            Prompt::cancelUsing(null);
        }
    }

    /**
     * Ask what a database needs before it's used: a new SQLite file, whether it gets the demo data; pending
     * migrations, whether to run them now (after a copy of a SQLite file). Get the answer, null when there's nothing
     * to ask, or `STOP` when it doesn't answer.
     *
     * @param Database $database
     *
     * @return bool|string|null
     */
    protected function migrationQuestion(Database $database): bool|string|null
    {
        if ($database->isNew()) {
            return $this->decide(
                ucfirst($database->label) . ' is new. Add the demo data?',
                'Yes — Практика Коваленко: sign in as olena@example.com / password',
                'No — an empty installation',
            );
        }

        $pending = $database->pending($this->toolchain);

        if ($pending === null) {
            error(ucfirst($database->label) . ' doesn\'t answer.');
            note($database->file === null ? 'Start MySQL (sudo systemctl start mysql), or choose SQLite.' : 'See ./prytax doctor.');

            return self::STOP;
        }

        if ($pending === []) {
            return null;
        }

        note(count($pending) . " migrations haven't run on $database->label: " . implode(', ', $pending));
        $copied = $database->file === null ? '' : ' (a copy of the file is kept beside it)';

        return $this->decide("Run them now$copied?", 'Yes — run them', 'No — not now');
    }

    /**
     * Bring a database up to date, as answered: make a new SQLite file (with the demo data, if wanted), or run its
     * pending migrations, after a copy of a SQLite file. Get false when it can't be used.
     *
     * @param Database $database
     * @param bool|string|null $answer
     *
     * @return bool
     */
    protected function migrateWith(Database $database, bool|string|null $answer): bool
    {
        if ($database->isNew()) {
            info("Making {$database->label}…");
            @mkdir(dirname((string) $database->file), 0755, true);
            touch((string) $database->file);

            if (!$this->artisan(['migrate', '--force', '--seed'], $database->env)) {
                return false;
            }

            return $answer !== true || $this->artisan(['db:seed', '--class=Database\Seeders\DemoSeeder', '--force'], $database->env);
        }

        if ($answer !== true) {
            return true;
        }

        if ($copy = $database->backup('migrate')) {
            info("Copied it to $copy.");
        }

        // The database seeder only syncs the roles, which new migrations may give new permissions
        return $this->artisan(['migrate', '--force'], $database->env) && $this->artisan(['db:seed', '--force'], $database->env);
    }

    /**
     * Ask whether to build the SPA, when its build is missing or older than its sources; null when it's fresh.
     *
     * @return bool|string|null
     */
    protected function buildQuestion(): bool|string|null
    {
        return match ($this->doctor->buildState()) {
            'fresh' => null,
            'missing' => $this->decide('The SPA isn\'t built. Build it now?', 'Yes — npm run build', 'No — the app can\'t start without it'),
            default => $this->decide('The SPA\'s build is older than its sources. Build it now?', 'Yes — npm run build', 'No — the older build'),
        };
    }

    /**
     * Build the SPA, as answered, and get false when it must be built but wasn't.
     *
     * @param bool|string|null $answer
     *
     * @return bool
     */
    protected function buildWith(bool|string|null $answer): bool
    {
        $state = $this->doctor->buildState();

        if ($state === 'fresh') {
            return true;
        }

        return $answer === true ? $this->run(['npm', 'run', 'build']) : $state === 'stale';
    }
}
