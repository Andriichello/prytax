<?php

namespace PryTax\Console\Starter;

use PryTax\Console\Commands\Start;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;

/**
 * Class DesktopAction.
 *
 * The desktop app's development window (`./prytax desktop`, builds.md §4.10): Node, Electron and its sandbox checked,
 * the SPA built and the desktop database migrated when they need it, then `native:run` in the foreground, with this
 * PHP as `php`, DevTools only when asked, and the DevTools protocol on 9333 for scripts; `n` serves its data to a phone
 * over an ngrok tunnel meanwhile. When it runs already, it offers that tunnel, or to close it.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Console\Starter
 */
class DesktopAction extends Action
{
    /**
     * Perform the action, and get its exit code.
     *
     * @return int
     */
    protected function handle(): int
    {
        if ($this->listeners->isListening(Listeners::DESKTOP_PORT)) {
            return $this->running();
        }

        $database = Database::desktop($this->toolchain);

        if (!$this->checked() || !$this->ensureBuild() || !$this->migrate($database)) {
            return Start::FAILURE;
        }

        $devtools = (bool) $this->start->option('devtools');
        info('Opening the desktop app' . ($devtools ? ' with DevTools' : '') . '. Close its window, or press Ctrl+C here, to stop it.');

        $group = $this->group();
        // Without interaction, its dev script doesn't take the terminal, which the group reads keys from; -v shows its log
        $group->start('desktop', [$this->toolchain->php(), 'artisan', 'native:run', '--no-dependencies', '--no-interaction', '-v'], $this->toolchain->env([
            'PRYTAX_DEVTOOLS' => $devtools ? 'true' : 'false',
            'REMOTE_DEBUGGING_PORT' => (string) Listeners::DEVTOOLS_PORT,
            ...self::SINGLE_ORGANIZATION,
        ]));

        $opened = $group->wait(['n' => ['open or close an ngrok tunnel for a phone, to its data', function () use ($group, $database) {
            $this->togglePhone($group, $database);
        }]]);

        return $opened ? Start::SUCCESS : Start::FAILURE;
    }

    /**
     * Offer a tunnel to the desktop app's data for a phone, or to close it, as it runs already, and get the exit code.
     *
     * @return int
     */
    protected function running(): int
    {
        if (!$this->start->isInteractive()) {
            info('The desktop app runs already. ./prytax stop desktop closes it.');

            return Start::SUCCESS;
        }

        return match ($this->start->menu('The desktop app runs already.', [
            'phone' => 'Open an ngrok tunnel for a phone, to its data',
            'close' => 'Close it',
        ])) {
            'phone' => $this->start->perform('phone', ['source' => 'desktop']),
            'close' => $this->stopService('desktop') ? Start::SUCCESS : Start::FAILURE,
            default => Start::SUCCESS,
        };
    }

    /**
     * Serve the desktop app's data to a phone over an ngrok tunnel in the group, or stop serving it.
     *
     * @param ProcessGroup $group
     * @param Database $database
     *
     * @return void
     */
    protected function togglePhone(ProcessGroup $group, Database $database): void
    {
        $tunnel = app(Tunnel::class);

        if ($group->running('phone') || $group->running('ngrok')) {
            $group->stop('ngrok');
            $group->stop('phone');
            $group->say('ngrok', 'Closed the tunnel.');

            return;
        }

        if (!$tunnel->isInstalled()) {
            $group->say('ngrok', '<fg=red>ngrok isn\'t installed:</> ' . Tunnel::INSTALL . '.');

            return;
        }

        if ($this->listeners->isListening(Listeners::PHONE_PORT)) {
            $group->say('phone', '<fg=red>The phone server needs port ' . Listeners::PHONE_PORT . ', which is in use.</>');

            return;
        }

        $group->say('ngrok', 'Opening a tunnel…');
        $this->servePhone($group, $database);
        $tunnel->open($group, '127.0.0.1:' . Listeners::PHONE_PORT, "on the phone, and sign in with an account of $database->label");
    }

    /**
     * Check what the window needs, offering to install a missing Electron, and get whether it can open.
     *
     * @return bool
     */
    protected function checked(): bool
    {
        foreach ($this->doctor->desktop() as $check) {
            if (!$check->failed()) {
                continue;
            }

            if ($check->name === 'Electron' && confirm('NativePHP\'s Electron isn\'t installed. Install it now?')) {
                if (!$this->artisan(['native:install', '--force', '--no-interaction'])) {
                    return false;
                }

                $check = $this->doctor->sandbox();

                if (!$check->failed()) {
                    continue;
                }
            }

            error("$check->name: $check->detail.");
            note((string) $check->hint);

            return false;
        }

        return true;
    }
}
