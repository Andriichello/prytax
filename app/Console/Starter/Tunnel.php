<?php

namespace PryTax\Console\Starter;

/**
 * Class Tunnel.
 *
 * An ngrok tunnel to PryTax for a phone, as a group's process `ngrok`, which tells the tunnel's public address once
 * ngrok's log has it, and ngrok's errors. The server behind it lets the address sign in: its own host stateful, and
 * ngrok's agent a proxy it trusts (`Action::tunnelled()`).
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Console\Starter
 */
class Tunnel
{
    /**
     * How to get ngrok, when it's missing.
     *
     * @var string
     */
    public const string INSTALL = 'snap install ngrok, then ngrok config add-authtoken <token>, from a free account at ngrok.com';

    /**
     * Tunnel constructor.
     *
     * @param Toolchain $toolchain
     */
    public function __construct(protected Toolchain $toolchain) {}

    /**
     * Determine whether ngrok is installed.
     *
     * @return bool
     */
    public function isInstalled(): bool
    {
        return $this->toolchain->has('ngrok');
    }

    /**
     * Open a tunnel to the address (`127.0.0.1:8010`) in the group, which then tells `Open <url> <then>.`
     *
     * @param ProcessGroup $group
     * @param string $address
     * @param string $then
     *
     * @return void
     */
    public function open(ProcessGroup $group, string $address, string $then): void
    {
        $opened = false;

        $group->start('ngrok', ['ngrok', 'http', $address, '--log=stdout', '--log-format=json'], $this->toolchain->env(), filter: function (string $line) use ($group, $then, &$opened): ?string {
            $entry = json_decode($line, true);

            if (!is_array($entry)) {
                return $line;
            }

            $url = $entry['url'] ?? null;

            if (!$opened && is_string($url) && str_starts_with($url, 'https://')) {
                $opened = true;
                $group->say('ngrok', "<options=bold>Open $url $then.</>");

                return null;
            }

            if (!in_array($entry['lvl'] ?? null, ['eror', 'crit'], true)) {
                return null;
            }

            return is_string($entry['err'] ?? null) ? $entry['err'] : (string) ($entry['msg'] ?? $line);
        });
    }

    /**
     * Open a tunnel to the address in the group, or close the one open.
     *
     * @param ProcessGroup $group
     * @param string $address
     * @param string $then
     *
     * @return void
     */
    public function toggle(ProcessGroup $group, string $address, string $then): void
    {
        if ($group->running('ngrok')) {
            $group->stop('ngrok');
            $group->say('ngrok', 'Closed the tunnel.');

            return;
        }

        if (!$this->isInstalled()) {
            $group->say('ngrok', '<fg=red>ngrok isn\'t installed:</> ' . self::INSTALL . '.');

            return;
        }

        $group->say('ngrok', 'Opening a tunnel…');
        $this->open($group, $address, $then);
    }
}
