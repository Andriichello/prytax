<?php

namespace PryTax\Console\Starter;

use PryTax\Console\Commands\Start;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;

/**
 * Class PhoneAction.
 *
 * PryTax on a phone (`./prytax phone`, or from the desktop app that runs already): PHP's server on 8012 behind an
 * ngrok tunnel, on the desktop app's data or the web app's, with the tunnel's address signing in, files for sessions
 * and cache, and registration off, so the address can't create organizations. Until Ctrl+C, which closes the tunnel
 * too.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Console\Starter
 */
class PhoneAction extends Action
{
    /**
     * Perform the action, and get its exit code.
     *
     * @return int
     */
    protected function handle(): int
    {
        $tunnel = app(Tunnel::class);

        if (!$tunnel->isInstalled()) {
            error('ngrok isn\'t installed.');
            note(Tunnel::INSTALL . '.');

            return Start::FAILURE;
        }

        if (!$this->free(Listeners::PHONE_PORT, 'The phone server')) {
            return Start::FAILURE;
        }

        $databaseOf = fn (string $source) => $source === 'web' ? Database::web() : Database::desktop($this->toolchain);
        $answers = $this->ask([
            'source' => fn () => $this->start->option('source') ?? $this->choose('Whose data should the phone see?', [
                'desktop' => 'The desktop app\'s — database/nativephp.sqlite and its files',
                'web' => 'The web app\'s — .env\'s database',
            ], 'desktop'),
            'migrate' => fn (array $answers) => $this->migrationQuestion($databaseOf((string) $answers['source'])),
            'build' => fn () => $this->buildQuestion(),
        ]);

        if ($answers === null) {
            return $this->ended();
        }

        $database = $databaseOf((string) $answers['source']);

        if (!$this->migrateWith($database, $answers['migrate']) || !$this->buildWith($answers['build'])) {
            return Start::FAILURE;
        }

        info('Opening an ngrok tunnel for the phone. Ctrl+C closes it.');
        $group = $this->group();
        $this->servePhone($group, $database);
        $tunnel->open($group, '127.0.0.1:' . Listeners::PHONE_PORT, "on the phone, and sign in with an account of $database->label");

        return $group->wait() ? Start::SUCCESS : Start::FAILURE;
    }
}
