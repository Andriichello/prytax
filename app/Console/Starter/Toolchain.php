<?php

namespace PryTax\Console\Starter;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Throwable;

/**
 * Class Toolchain.
 *
 * The programs the starter's child processes run: this PHP (8.5, which the system's `php` may not be), as `php` on
 * their PATH, since `native:run` and `native:build` call it by that name; and a Node recent enough for Vite and
 * NativePHP's Electron, from nvm when the PATH's is older.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Console\Starter
 */
class Toolchain
{
    /**
     * The lowest major version of Node, which NativePHP's Electron runs on (Vite needs 20.19).
     *
     * @var int
     */
    public const int NODE_MAJOR = 22;

    /**
     * Get the PHP binary, which runs the starter.
     *
     * @return string
     */
    public function php(): string
    {
        return PHP_BINARY;
    }

    /**
     * Get a directory with `php` linked to this PHP, made once per user in the system's temporary directory.
     *
     * @return string
     */
    public function phpDirectory(): string
    {
        $directory = sys_get_temp_dir() . '/prytax-' . getmyuid() . '/bin';
        $link = "$directory/php";

        if (!is_dir($directory)) {
            mkdir($directory, 0700, true);
        }

        if (!is_link($link) || readlink($link) !== $this->php()) {
            @unlink($link);
            symlink($this->php(), $link);
        }

        return $directory;
    }

    /**
     * Get the directory of a Node recent enough, when the PATH's is older or missing: nvm's newest, or null.
     *
     * @return string|null
     */
    public function nodeDirectory(): ?string
    {
        if ($this->majorOf($this->version('node')) >= self::NODE_MAJOR) {
            return null;
        }

        $nvm = getenv('NVM_DIR') ?: getenv('HOME') . '/.nvm';
        $versions = collect(glob("$nvm/versions/node/v*/bin/node") ?: [])
            ->mapWithKeys(fn (string $node) => [dirname($node) => Str::between($node, '/node/v', '/bin')])
            ->filter(fn (string $version) => $this->majorOf($version) >= self::NODE_MAJOR)
            ->sort(fn (string $a, string $b) => version_compare($b, $a));

        return $versions->keys()->first();
    }

    /**
     * Get the version of the Node, which the child processes run, or null without one.
     *
     * @return string|null
     */
    public function nodeVersion(): ?string
    {
        $directory = $this->nodeDirectory();

        return $this->version($directory === null ? 'node' : "$directory/node");
    }

    /**
     * Get the environment of a child process: the PATH with this PHP and a recent Node first, and the given variables.
     *
     * @param array<string, string|bool> $variables
     *
     * @return array<string, string|bool>
     */
    public function env(array $variables = []): array
    {
        $path = implode(':', array_filter([$this->phpDirectory(), $this->nodeDirectory(), (string) getenv('PATH')]));

        return ['PATH' => $path, ...$variables];
    }

    /**
     * Get the storage folder of the desktop app while developing (`native:run`): NativePHP's userData, `-dev`.
     *
     * @return string
     */
    public function desktopStoragePath(): string
    {
        $folder = Str::slug((string) config('app.name')) . '-dev';
        $base = PHP_OS_FAMILY === 'Darwin'
            ? getenv('HOME') . '/Library/Application Support'
            : (getenv('XDG_CONFIG_HOME') ?: getenv('HOME') . '/.config');

        return "$base/$folder/storage";
    }

    /**
     * Get whether a program can be found on the PATH.
     *
     * @param string $program
     *
     * @return bool
     */
    public function has(string $program): bool
    {
        return Process::run(['sh', '-c', 'command -v ' . escapeshellarg($program)])->successful();
    }

    /**
     * Get a program's version, without a leading `v`, or null when it doesn't run.
     *
     * @param string $program
     *
     * @return string|null
     */
    protected function version(string $program): ?string
    {
        try {
            $result = Process::run([$program, '-v']);
        } catch (Throwable) {
            return null;
        }

        return $result->successful() ? ltrim(trim($result->output()), 'v') : null;
    }

    /**
     * Get the major number of a version, 0 of none.
     *
     * @param string|null $version
     *
     * @return int
     */
    protected function majorOf(?string $version): int
    {
        return (int) Str::before((string) $version, '.');
    }
}
