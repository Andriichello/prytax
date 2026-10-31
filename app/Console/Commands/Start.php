<?php

namespace PryTax\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use PryTax\Console\Starter\Action;
use PryTax\Console\Starter\BuildAction;
use PryTax\Console\Starter\DatabaseAction;
use PryTax\Console\Starter\DesktopAction;
use PryTax\Console\Starter\DockerAction;
use PryTax\Console\Starter\DoctorAction;
use PryTax\Console\Starter\Listeners;
use PryTax\Console\Starter\McpAction;
use PryTax\Console\Starter\Menu;
use PryTax\Console\Starter\PhoneAction;
use PryTax\Console\Starter\StarterState;
use PryTax\Console\Starter\StatusAction;
use PryTax\Console\Starter\Summaries;
use PryTax\Console\Starter\SummaryTable;
use PryTax\Console\Starter\TestAction;
use PryTax\Console\Starter\Toolchain;
use PryTax\Console\Starter\WebAction;
use Symfony\Component\Console\Input\InputInterface;

use function Laravel\Prompts\error;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;
use function Laravel\Prompts\spin;

/**
 * Class Start.
 *
 * PryTax's starter (`./prytax`, which runs it with PHP 8.5): a menu, or an action named, that starts PryTax as a web
 * app (in Docker or here) or as the desktop app, always as one person's installation, with an ngrok tunnel for a
 * phone, shows and stops what runs, checks the machine, and runs the tests, the builds and the databases' tasks
 * (builds.md §6). The menu shows the last app started with its overview, and offers it again first; then asks for the
 * platform, then how to run it; the tools are below it, beside Quit, and Esc quits. In its container (`./prytax` on a
 * machine without PHP 8.5) it offers only what runs there: the web app here and the tools.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Console\Commands
 */
#[Signature('prytax:start
    {action? : web, docker, desktop, phone, status, stop, doctor, test, build, db or mcp}
    {what?* : stop: what to stop, such as web vite desktop phone ngrok docker}
    {--db= : web: mysql or sqlite; docker, for new settings: sqlite (the default), mysql or external}
    {--sqlite= : web: the SQLite file, database/database.sqlite by default}
    {--port= : web: the port, APP_URL\'s by default}
    {--vite : web: Vite\'s hot reload rather than the built SPA}
    {--mysql : docker: MySQL in a container}
    {--env-file= : docker: the settings, .env.docker by default}
    {--devtools : desktop: open DevTools in the window}
    {--source= : phone: whose data, desktop or web}
    {--all : stop: everything that runs, without asking}
    {--filter= : test: only the tests that match}')]
#[Description('Start PryTax as a web or a desktop app, or check, test, build or migrate it')]
class Start extends Command
{
    /**
     * The actions' classes, by name.
     *
     * @var array<string, class-string>
     */
    protected const array ACTIONS = [
        'web' => WebAction::class,
        'docker' => DockerAction::class,
        'desktop' => DesktopAction::class,
        'phone' => PhoneAction::class,
        'status' => StatusAction::class,
        'stop' => StatusAction::class,
        'doctor' => DoctorAction::class,
        'test' => TestAction::class,
        'build' => BuildAction::class,
        'db' => DatabaseAction::class,
        'mcp' => McpAction::class,
    ];

    /**
     * The tools, which the menu's Tools lists, by action.
     *
     * @var array<string, string>
     */
    protected const array TOOLS = [
        'status' => 'Status — what runs, and stopping it',
        'doctor' => 'Doctor — check what PryTax needs here',
        'test' => 'Tests — PHP and SPA',
        'build' => 'Build — the SPA, the desktop installers, the thumbnails',
        'db' => 'Database — migrations, demo data, the first owner',
        'mcp' => 'Claude — connect it to PryTax\'s data (MCP)',
    ];

    /**
     * The actions, which need this machine rather than the starter's container, with why.
     *
     * @var array<string, string>
     */
    protected const array HOST_ONLY = [
        'desktop' => 'The desktop app\'s window needs PHP 8.5 on this machine: install it as php8.5, or set PRYTAX_PHP.',
        'docker' => 'Docker can\'t be started from the starter\'s container: ./start runs PryTax in Docker without PHP.',
        'phone' => 'The phone over ngrok needs PHP 8.5 and ngrok on this machine: install PHP as php8.5, or set PRYTAX_PHP.',
        'mcp' => 'Claude starts PryTax\'s server with PHP 8.5 on this machine: install it as php8.5, or set PRYTAX_PHP.',
    ];

    /**
     * Whether the menu runs, rather than an action named, so that the actions' prompts offer Back too.
     *
     * @var bool
     */
    protected bool $inMenu = false;

    /**
     * Whether Quit was chosen.
     *
     * @var bool
     */
    protected bool $quitting = false;

    /**
     * Execute the console command.
     *
     * @param Listeners $listeners
     * @param Toolchain $toolchain
     * @param StarterState $state
     * @param Summaries $summaries
     *
     * @return int
     */
    public function handle(Listeners $listeners, Toolchain $toolchain, StarterState $state, Summaries $summaries): int
    {
        $action = $this->argument('action');
        $inContainer = $toolchain->inContainer();

        if ($action !== null) {
            if (!array_key_exists($action, self::ACTIONS)) {
                error("There's no action $action: " . implode(', ', array_keys(self::ACTIONS)) . '.');

                return self::INVALID;
            }

            if ($inContainer && isset(self::HOST_ONLY[$action])) {
                error(self::HOST_ONLY[$action]);

                return self::FAILURE;
            }

            Menu::escapeQuits();

            return $this->perform($action);
        }

        if (!$this->isInteractive()) {
            error('Name an action: ' . implode(', ', array_keys(self::ACTIONS)) . '.');

            return self::INVALID;
        }

        Menu::escapeQuits();
        intro('PryTax');
        $this->showRunning($listeners);
        $this->showLast($state, $summaries);
        $this->inMenu = true;

        while (!$this->quitting) {
            $running = $listeners->running();
            $last = $this->offered($state->last(), $inContainer);
            $platform = $this->choose('Which platform?', array_filter([
                'again' => $last === null ? null : "Again — {$last['label']}",
                'web' => 'Web — ' . match (true) {
                    isset($running['web']) => "runs here at {$running['web']['url']}",
                    isset($running['docker']) => "runs in Docker at {$running['docker']['url']}",
                    $inContainer => 'here',
                    default => 'in Docker or here',
                },
                'desktop' => $inContainer ? null : 'Desktop — ' . (isset($running['desktop']) ? 'the development window runs' : 'the development window'),
            ]), ['tools' => 'Tools — status, doctor, tests, builds, database'], $last === null ? '' : $this->noteOf($last));

            match ($platform) {
                'again' => $this->again((array) $last),
                'web' => $inContainer ? $this->perform('web') : $this->web($running),
                'desktop' => $this->desktop($running),
                'tools' => $this->tools(),
                default => null,
            };
        }

        return self::SUCCESS;
    }

    /**
     * Determine whether the user answers the questions.
     *
     * @return bool
     */
    public function isInteractive(): bool
    {
        return $this->input->isInteractive();
    }

    /**
     * Ask which of the options, with Back below them in the menu and Quit last (Esc), and get the option's value,
     * `back` or `quit`, which ends the menu.
     *
     * @param string $label
     * @param array<string, string> $options
     * @param string $hint
     *
     * @return string
     */
    public function menu(string $label, array $options, string $hint = ''): string
    {
        return $this->choose($label, $options, $this->inMenu ? ['back' => 'Back'] : [], $hint);
    }

    /**
     * Ask which of the options, with Back below them when there's somewhere to go back (an action's earlier question,
     * or the menu), and Quit last (Esc), and get the option's value, `back` or `quit`.
     *
     * @param string $label
     * @param array<string, string> $options
     * @param bool $back
     * @param string|null $default
     * @param string $hint
     *
     * @return string
     */
    public function pick(string $label, array $options, bool $back, ?string $default = null, string $hint = ''): string
    {
        return $this->choose($label, $options, $back ? ['back' => 'Back'] : [], $hint, $default);
    }

    /**
     * Determine whether the menu runs, which an action's first question may go back to.
     *
     * @return bool
     */
    public function isInMenu(): bool
    {
        return $this->inMenu;
    }

    /**
     * Determine whether Quit was chosen.
     *
     * @return bool
     */
    public function isQuitting(): bool
    {
        return $this->quitting;
    }

    /**
     * Perform an action, with the options given, and get its exit code.
     *
     * @param string $action
     * @param array<string, mixed> $options
     *
     * @return int
     */
    public function perform(string $action, array $options = []): int
    {
        $previous = [];

        foreach ($options as $name => $value) {
            $previous[$name] = $this->input->getOption($name);
            $this->input->setOption($name, $value);
        }

        try {
            return app(self::ACTIONS[$action])($this);
        } finally {
            // So that the menu's next choice asks again
            foreach ($previous as $name => $value) {
                $this->input->setOption($name, $value);
            }
        }
    }

    /**
     * Ask which of the options, with the lines below them and Quit last, and get the value chosen.
     *
     * @param string $label
     * @param array<string, string> $options
     * @param array<string, string> $below
     * @param string $hint
     *
     * @return string
     */
    protected function choose(string $label, array $options, array $below = [], string $hint = '', ?string $default = null): string
    {
        $choice = (string) (new Menu($label, $options, [...$below, 'quit' => 'Quit'], $default, $hint))->prompt();
        $this->quitting = $this->quitting || $choice === 'quit';

        return $choice;
    }

    /**
     * Ask where the web app should run, and run it there.
     *
     * @param array<string, array{label: string, port: int, url: string|null}> $running
     *
     * @return void
     */
    protected function web(array $running): void
    {
        // Back from the action's first question comes here again
        do {
            $choice = $this->menu('Where should the web app run?', [
                'docker' => isset($running['docker']) ? "In Docker — runs at {$running['docker']['url']}" : 'In Docker — the image, with its worker and scheduler',
                'web' => isset($running['web']) ? "Here — runs at {$running['web']['url']}" : 'Here — PHP\'s server, the queue worker and the scheduler',
            ]);
            $result = in_array($choice, ['web', 'docker'], true) ? $this->perform($choice) : null;
        } while ($result === Action::WENT_BACK && !$this->quitting);
    }

    /**
     * Ask how the desktop app should open, and open it, or offer what's done with it when it runs already.
     *
     * @param array<string, array{label: string, port: int, url: string|null}> $running
     *
     * @return void
     */
    protected function desktop(array $running): void
    {
        if (isset($running['desktop'])) {
            $this->perform('desktop');

            return;
        }

        do {
            $choice = $this->menu('How should the desktop app open?', [
                'window' => 'The development window',
                'devtools' => 'The development window, with DevTools',
            ]);
            $result = in_array($choice, ['window', 'devtools'], true) ? $this->perform('desktop', ['devtools' => $choice === 'devtools']) : null;
        } while ($result === Action::WENT_BACK && !$this->quitting);
    }

    /**
     * Ask which tool, and run it, until Back or Quit.
     *
     * @return void
     */
    protected function tools(): void
    {
        // In the starter's container, the tools, which need this machine, aren't offered
        $tools = app(Toolchain::class)->inContainer() ? array_diff_key(self::TOOLS, self::HOST_ONLY) : self::TOOLS;

        do {
            $tool = $this->menu('Which tool?', $tools);

            if (array_key_exists($tool, $tools)) {
                $this->perform($tool);
            }
        } while (!$this->quitting && $tool !== 'back');
    }

    /**
     * Start the last choice's app again, with what was chosen; what isn't remembered (Vite, DevTools) is asked or left
     * as it was.
     *
     * @param array{choice: string, sqlite: string|null} $last
     *
     * @return void
     */
    protected function again(array $last): void
    {
        match ($last['choice']) {
            'desktop' => $this->perform('desktop'),
            'web-here-mysql' => $this->perform('web', ['db' => 'mysql']),
            'web-here-sqlite' => $this->perform('web', ['db' => 'sqlite', 'sqlite' => $last['sqlite']]),
            'web-docker-sqlite' => $this->perform('docker', ['db' => 'sqlite']),
            'web-docker-mysql' => $this->perform('docker', ['db' => 'mysql']),
            default => null,
        };
    }

    /**
     * Get the last choice, when the menu can offer it again: not the desktop app or Docker in the container.
     *
     * @param array{choice: string, label: string, sqlite: string|null, run_at: string|null, run_by: string|null, set_up_at: string|null, database_created_at: string|null}|null $last
     * @param bool $inContainer
     *
     * @return array{choice: string, label: string, sqlite: string|null, run_at: string|null, run_by: string|null, set_up_at: string|null, database_created_at: string|null}|null
     */
    protected function offered(?array $last, bool $inContainer): ?array
    {
        return $last === null || ($inContainer && !str_starts_with($last['choice'], 'web-here')) ? null : $last;
    }

    /**
     * Get the note of the last choice, short enough for a narrow terminal: when it was set up, and when its database
     * was created (its last run is told above the overview).
     *
     * @param array{set_up_at: string|null, database_created_at: string|null} $last
     *
     * @return string
     */
    protected function noteOf(array $last): string
    {
        return implode(' · ', array_filter([
            $last['set_up_at'] === null ? null : 'Set up ' . SummaryTable::day($last['set_up_at']),
            $last['database_created_at'] === null ? null : 'database created ' . SummaryTable::day($last['database_created_at']),
        ]));
    }

    /**
     * Show the last choice's app with its overview (its organizations, their ФОПs, their storage and latest backups),
     * as its database tells now, and keep the date its database was created.
     *
     * @param StarterState $state
     * @param Summaries $summaries
     *
     * @return void
     */
    protected function showLast(StarterState $state, Summaries $summaries): void
    {
        $last = $state->last();

        if ($last === null) {
            return;
        }

        $summary = spin(fn () => $summaries->of($last['choice'], $last['sqlite']), "Reading {$last['label']}…");
        $this->output->writeln('');
        $run = $last['run_at'] === null ? '' : ', ' . SummaryTable::moment($last['run_at']) . ($last['run_by'] === null ? '' : " by ./{$last['run_by']}");
        $this->output->writeln("  <options=bold>Last: {$last['label']}</>$run");

        if ($summary === null) {
            note(str_starts_with($last['choice'], 'web-docker')
                ? 'Its overview shows while it runs.'
                : 'Its overview can\'t be read now: its database isn\'t there yet, or doesn\'t answer.');

            return;
        }

        $state->rememberDatabaseCreated($last['choice'], $summary['database']['created_at'] ?? null);
        SummaryTable::render($summary, $this->output);
    }

    /**
     * Tell which of PryTax's apps run, and what else of it does (Vite, the phone server, ngrok).
     *
     * @param Listeners $listeners
     *
     * @return void
     */
    protected function showRunning(Listeners $listeners): void
    {
        $running = $listeners->running();
        $apps = ['web', 'docker', 'desktop'];
        $services = $listeners->services();
        unset($services['mysql']);

        // The apps first, in this order
        foreach (array_replace(array_flip($apps), $services) as $key => ['label' => $label, 'url' => $url]) {
            if (isset($running[$key])) {
                $this->output->writeln(sprintf('  <fg=green>●</> %-18s runs%s', $label, $url === null ? '' : " at $url"));
            } elseif (in_array($key, $apps, true)) {
                $this->output->writeln(sprintf('  <fg=gray>○ %-18s not running</>', $label));
            }
        }
    }

    /**
     * Configure the prompts' fallbacks, the menu's among them: its hint, then a choice of its options and the lines
     * below them.
     *
     * @param InputInterface $input
     *
     * @return void
     */
    protected function configurePrompts(InputInterface $input): void
    {
        parent::configurePrompts($input);

        Menu::fallbackUsing(function (Menu $menu) {
            if ($menu->hint !== '') {
                $this->line("  <fg=gray>$menu->hint</>");
            }

            return $this->components->choice($menu->label, [...$menu->options, ...$menu->below], $menu->default);
        });
    }
}
