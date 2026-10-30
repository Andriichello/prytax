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
use PryTax\Console\Starter\PhoneAction;
use PryTax\Console\Starter\StatusAction;
use PryTax\Console\Starter\TestAction;
use PryTax\Console\Starter\WebAction;

use function Laravel\Prompts\error;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;
use function Laravel\Prompts\select;

/**
 * Class Start.
 *
 * PryTax's starter (`./prytax`, which runs it with PHP 8.5): a menu, or an action named, that starts PryTax as a web
 * app (here or in Docker), as the desktop app or for a phone, shows and stops what runs, checks the machine, and
 * runs the tests, the builds and the databases' tasks (builds.md §6).
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Console\Commands
 */
#[Signature('prytax:start
    {action? : web, docker, desktop, phone, status, stop, doctor, test, build or db}
    {what?* : stop: what to stop, such as web vite desktop phone ngrok docker}
    {--db= : web: mysql or sqlite}
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
     * The actions, by name: their class, and their line in the menu.
     *
     * @var array<string, array{0: class-string, 1: string}>
     */
    protected const array ACTIONS = [
        'web' => [WebAction::class, 'Web app — here: server, queue worker and scheduler, on MySQL or SQLite'],
        'docker' => [DockerAction::class, 'Web app — in Docker'],
        'desktop' => [DesktopAction::class, 'Desktop app — the development window'],
        'phone' => [PhoneAction::class, 'Phone — the app over an ngrok tunnel'],
        'status' => [StatusAction::class, 'Status — what runs, and stopping it'],
        'doctor' => [DoctorAction::class, 'Doctor — check what PryTax needs here'],
        'test' => [TestAction::class, 'Tests — PHP and SPA'],
        'build' => [BuildAction::class, 'Build — the SPA, the desktop installers, the thumbnails'],
        'db' => [DatabaseAction::class, 'Database — migrations, demo data, the first owner'],
        'stop' => [StatusAction::class, 'Stop — what runs'],
    ];

    /**
     * The actions, after which the menu comes back: those that don't keep running in the foreground.
     *
     * @var list<string>
     */
    protected const array RETURNING = ['docker', 'status', 'doctor', 'test', 'build', 'db'];

    /**
     * Execute the console command.
     *
     * @param Listeners $listeners
     *
     * @return int
     */
    public function handle(Listeners $listeners): int
    {
        $action = $this->argument('action');

        if ($action !== null) {
            if (!array_key_exists($action, self::ACTIONS)) {
                error("There's no action $action: " . implode(', ', array_keys(self::ACTIONS)) . '.');

                return self::INVALID;
            }

            return $this->perform($action);
        }

        if (!$this->isInteractive()) {
            error('Name an action: ' . implode(', ', array_keys(self::ACTIONS)) . '.');

            return self::INVALID;
        }

        intro('PryTax');
        $this->noteRunning($listeners);
        $menu = array_map(fn (array $action) => $action[1], array_diff_key(self::ACTIONS, ['stop' => true]));

        do {
            $action = select('What do you want to do?', [...$menu, 'quit' => 'Quit'], default: 'web', scroll: 11);
            $result = $action === 'quit' ? self::SUCCESS : $this->perform($action);
        } while (in_array($action, self::RETURNING, true));

        return $result;
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
     * Perform an action, and get its exit code.
     *
     * @param string $action
     *
     * @return int
     */
    protected function perform(string $action): int
    {
        return app(self::ACTIONS[$action][0])($this);
    }

    /**
     * Tell what of PryTax runs already, if anything.
     *
     * @param Listeners $listeners
     *
     * @return void
     */
    protected function noteRunning(Listeners $listeners): void
    {
        $listening = $listeners->listening();
        $running = collect($listeners->services())
            ->filter(fn (array $service) => array_key_exists($service['port'], $listening))
            ->map(fn (array $service) => "{$service['label']} ({$service['port']})");

        if ($running->isNotEmpty()) {
            note('Running: ' . $running->implode(', ') . '.');
        }
    }
}
