<?php

namespace PryTax\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use PryTax\Console\Starter\BuildAction;
use PryTax\Console\Starter\DatabaseAction;
use PryTax\Console\Starter\DesktopAction;
use PryTax\Console\Starter\DockerAction;
use PryTax\Console\Starter\DoctorAction;
use PryTax\Console\Starter\Listeners;
use PryTax\Console\Starter\Menu;
use PryTax\Console\Starter\PhoneAction;
use PryTax\Console\Starter\StatusAction;
use PryTax\Console\Starter\TestAction;
use PryTax\Console\Starter\Toolchain;
use PryTax\Console\Starter\WebAction;
use Symfony\Component\Console\Input\InputInterface;

use function Laravel\Prompts\error;
use function Laravel\Prompts\intro;

/**
 * Class Start.
 *
 * PryTax's starter (`./prytax`, which runs it with PHP 8.5): a menu, or an action named, that starts PryTax as a web
 * app (in Docker or here) or as the desktop app, always as one person's installation, with an ngrok tunnel for a
 * phone, shows and stops what runs, checks the machine, and runs the tests, the builds and the databases' tasks
 * (builds.md §6). The menu asks for the platform first, then how to run it; the tools are below it, beside Quit, and
 * Esc quits. In its container (`./prytax` on a machine without PHP 8.5) it offers only what runs there: the web app
 * here and the tools.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Console\Commands
 */
#[Signature('prytax:start
    {action? : web, docker, desktop, phone, status, stop, doctor, test, build or db}
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
     *
     * @return int
     */
    public function handle(Listeners $listeners, Toolchain $toolchain): int
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
        $this->inMenu = true;

        while (!$this->quitting) {
            $running = $listeners->running();
            $platform = $this->choose('Which platform?', array_filter([
                'web' => 'Web — ' . match (true) {
                    isset($running['web']) => "runs here at {$running['web']['url']}",
                    isset($running['docker']) => "runs in Docker at {$running['docker']['url']}",
                    $inContainer => 'here',
                    default => 'in Docker or here',
                },
                'desktop' => $inContainer ? null : 'Desktop — ' . (isset($running['desktop']) ? 'the development window runs' : 'the development window'),
            ]), ['tools' => 'Tools — status, doctor, tests, builds, database']);

            match ($platform) {
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
     * Perform an action, with the options given, and get its exit code.
     *
     * @param string $action
     * @param array<string, mixed> $options
     *
     * @return int
     */
    public function perform(string $action, array $options = []): int
    {
        foreach ($options as $name => $value) {
            $this->input->setOption($name, $value);
        }

        return app(self::ACTIONS[$action])($this);
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
    protected function choose(string $label, array $options, array $below = [], string $hint = ''): string
    {
        $choice = (string) (new Menu($label, $options, [...$below, 'quit' => 'Quit'], hint: $hint))->prompt();
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
        $choice = $this->menu('Where should the web app run?', [
            'docker' => isset($running['docker']) ? "In Docker — runs at {$running['docker']['url']}" : 'In Docker — the image, with its worker and scheduler',
            'web' => isset($running['web']) ? "Here — runs at {$running['web']['url']}" : 'Here — PHP\'s server, the queue worker and the scheduler',
        ]);

        if (in_array($choice, ['web', 'docker'], true)) {
            $this->perform($choice);
        }
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

        $choice = $this->menu('How should the desktop app open?', [
            'window' => 'The development window',
            'devtools' => 'The development window, with DevTools',
        ]);

        if (in_array($choice, ['window', 'devtools'], true)) {
            $this->perform('desktop', ['devtools' => $choice === 'devtools']);
        }
    }

    /**
     * Ask which tool, and run it, until Back or Quit.
     *
     * @return void
     */
    protected function tools(): void
    {
        do {
            $tool = $this->menu('Which tool?', self::TOOLS);

            if (array_key_exists($tool, self::TOOLS)) {
                $this->perform($tool);
            }
        } while (!$this->quitting && $tool !== 'back');
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
     * Configure the prompts' fallbacks, the menu's among them: a choice of its options and the lines below them.
     *
     * @param InputInterface $input
     *
     * @return void
     */
    protected function configurePrompts(InputInterface $input): void
    {
        parent::configurePrompts($input);

        Menu::fallbackUsing(fn (Menu $menu) => $this->components->choice($menu->label, [...$menu->options, ...$menu->below], $menu->default));
    }
}
