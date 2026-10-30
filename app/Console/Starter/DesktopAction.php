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
 * PHP as `php`, DevTools only when asked, and the DevTools protocol on 9333 for scripts.
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
            info('The desktop app runs already. ./prytax stop closes it.');

            return Start::SUCCESS;
        }

        if (!$this->checked() || !$this->ensureBuild() || !$this->migrate(Database::desktop($this->toolchain))) {
            return Start::FAILURE;
        }

        $devtools = (bool) $this->start->option('devtools');
        info('Opening the desktop app' . ($devtools ? ' with DevTools' : '') . '. Close its window, or press Ctrl+C here, to stop it.');

        $opened = $this->artisan(['native:run', '--no-dependencies'], [
            'PRYTAX_DEVTOOLS' => $devtools ? 'true' : 'false',
            'REMOTE_DEBUGGING_PORT' => (string) Listeners::DEVTOOLS_PORT,
        ]);

        return $opened ? Start::SUCCESS : Start::FAILURE;
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
