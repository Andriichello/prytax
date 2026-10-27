<?php

namespace Tests\Feature\DataLifecycle;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Class ModuleBoundariesTest.
 *
 * The direction of dependencies around the backups and retention module (backups.md §10.3): the engine never uses
 * the app; the app uses the module only through its contracts, events, models, jobs and routes, never its
 * `Internal` classes; retention may use backup, never the reverse. Every PHP file of `app/` is read with
 * `token_get_all()`, and the classes it imports or names are checked.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\DataLifecycle
 */
class ModuleBoundariesTest extends TestCase
{
    /**
     * Seed nothing: the test reads files only.
     *
     * @var bool
     */
    protected bool $seed = false;

    /**
     * Namespace of the module.
     *
     * @var string
     */
    private const string MODULE = 'PryTax\DataLifecycle\\';

    /**
     * Test that the engine uses no class of the app outside the module.
     *
     * @return void
     */
    public function testTheEngineDoesntDependOnTheApp(): void
    {
        $this->assertNoUse(
            fn (string $class) => $this->inModule($class),
            fn (string $used) => str_starts_with($used, 'PryTax\\') && !str_starts_with($used, self::MODULE),
        );
    }

    /**
     * Test that the app uses no internal class of the module.
     *
     * @return void
     */
    public function testTheAppUsesNoInternalClassOfTheModule(): void
    {
        $this->assertNoUse(
            fn (string $class) => !$this->inModule($class),
            fn (string $used) => str_starts_with($used, self::MODULE . 'Backup\Internal\\')
                || str_starts_with($used, self::MODULE . 'Retention\Internal\\'),
        );
    }

    /**
     * Test that backup uses nothing of retention.
     *
     * @return void
     */
    public function testBackupDoesntDependOnRetention(): void
    {
        $this->assertNoUse(
            fn (string $class) => str_starts_with($class, self::MODULE . 'Backup\\'),
            fn (string $used) => str_starts_with($used, self::MODULE . 'Retention\\'),
        );
    }

    /**
     * Test that the reader finds imported, qualified and fully qualified names, as the rules need.
     *
     * @return void
     */
    public function testFindsTheClassesAFileUses(): void
    {
        $code = <<<'PHP'
            <?php

            namespace PryTax\DataLifecycle\Backup;

            use PryTax\Models\Profile;
            use PryTax\DataLifecycle\Retention as R;

            class Example
            {
                use Internal\Helper;

                public function run(): void
                {
                    new \PryTax\Services\Invoices\InvoiceFreezer();
                    R\Models\RetentionRun::query();
                    $callback = function () use ($x) {};
                }
            }
            PHP;

        [$class, $used] = $this->classesOf($code);

        $this->assertSame('PryTax\DataLifecycle\Backup\Example', $class);
        $this->assertEqualsCanonicalizing([
            'PryTax\Models\Profile',
            'PryTax\DataLifecycle\Retention',
            'PryTax\DataLifecycle\Backup\Internal\Helper',
            'PryTax\Services\Invoices\InvoiceFreezer',
            'PryTax\DataLifecycle\Retention\Models\RetentionRun',
        ], $used);
    }

    /**
     * Assert that no class, which the first callback selects, uses a class, which the second one forbids.
     *
     * @param callable(string): bool $applies
     * @param callable(string): bool $forbidden
     *
     * @return void
     */
    private function assertNoUse(callable $applies, callable $forbidden): void
    {
        $violations = [];
        $checked = 0;

        foreach ($this->files() as $file) {
            [$class, $used] = $this->classesOf((string) file_get_contents($file));

            if ($class === null || !$applies($class)) {
                continue;
            }

            $checked++;

            foreach ($used as $name) {
                if ($forbidden($name)) {
                    $violations[] = str_replace(base_path() . '/', '', $file) . " uses $name";
                }
            }
        }

        $this->assertGreaterThan(0, $checked);
        $this->assertSame([], $violations, implode(PHP_EOL, $violations));
    }

    /**
     * Determine if the class is the module's.
     *
     * @param string $class
     *
     * @return bool
     */
    private function inModule(string $class): bool
    {
        return str_starts_with($class, self::MODULE);
    }

    /**
     * Get the PHP files of the app.
     *
     * @return array<int, string>
     */
    private function files(): array
    {
        $files = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * Get the class, which a file declares (its namespace and name, or its namespace for a file without a class), and
     * the classes it uses: its imports, and the qualified names of its code resolved against them and its namespace.
     *
     * @param string $code
     *
     * @return array{0: string|null, 1: array<int, string>}
     */
    private function classesOf(string $code): array
    {
        $tokens = token_get_all($code);
        $namespace = '';
        $class = null;
        $aliases = [];
        $used = [];
        $depth = 0;
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (!is_array($token)) {
                $depth += match ($token) {
                    '{' => 1,
                    '}' => -1,
                    default => 0,
                };

                continue;
            }

            [$id, $text] = $token;

            if ($id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $depth++;

                continue;
            }

            if ($id === T_NAMESPACE) {
                $namespace = $this->nextName($tokens, $i) ?? '';

                continue;
            }

            if (in_array($id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true) && $class === null) {
                $name = $this->nextName($tokens, $i);
                $class = $name === null ? null : ltrim("$namespace\\$name", '\\');

                continue;
            }

            // An import: only outside of every class and function (a trait's or a closure's `use` is inside)
            if ($id === T_USE && $depth === 0) {
                $name = $this->nextName($tokens, $i);

                if ($name !== null) {
                    $alias = substr((string) strrchr("\\$name", '\\'), 1);

                    if (($tokens[$i + 2][0] ?? null) === T_AS) {
                        $alias = $tokens[$i + 4][1] ?? $alias;
                    }

                    $aliases[$alias] = $name;
                    $used[] = $name;
                }

                continue;
            }

            if ($id === T_NAME_FULLY_QUALIFIED) {
                $used[] = ltrim($text, '\\');
            } elseif ($id === T_NAME_QUALIFIED) {
                $first = strtok($text, '\\');
                $used[] = isset($aliases[$first])
                    ? $aliases[$first] . substr($text, strlen($first))
                    : ltrim("$namespace\\$text", '\\');
            }
        }

        return [$class ?? ($namespace === '' ? null : $namespace), array_values(array_unique($used))];
    }

    /**
     * Get the name, which follows the token, skipping whitespace, and move to it.
     *
     * @param array $tokens
     * @param int $i
     *
     * @return string|null
     */
    private function nextName(array $tokens, int &$i): ?string
    {
        for ($j = $i + 1; $j < count($tokens); $j++) {
            $token = $tokens[$j];

            if (is_array($token) && $token[0] === T_WHITESPACE) {
                continue;
            }

            if (is_array($token) && in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                $i = $j;

                return ltrim($token[1], '\\');
            }

            return null;
        }

        return null;
    }
}
