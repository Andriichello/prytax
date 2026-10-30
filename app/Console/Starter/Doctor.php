<?php

namespace PryTax\Console\Starter;

use FilesystemIterator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/**
 * Class Doctor.
 *
 * What running PryTax needs on this machine, checked one by one with what fixes each: PHP and its extensions,
 * api-core, `.env`, Node and the SPA's build, MySQL and the migrations, the desktop app's Electron and its sandbox,
 * Docker and ngrok.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Console\Starter
 */
class Doctor
{
    /**
     * The PHP extensions PryTax needs: the databases, zip (backups), gd (PDFs), bcmath, intl, and pcntl and posix for
     * the queue's timeouts and the starter.
     *
     * @var list<string>
     */
    protected const array EXTENSIONS = ['pdo_mysql', 'pdo_sqlite', 'zip', 'gd', 'bcmath', 'mbstring', 'fileinfo', 'intl', 'pcntl', 'posix'];

    /**
     * Doctor constructor.
     *
     * @param Toolchain $toolchain
     */
    public function __construct(protected Toolchain $toolchain) {}

    /**
     * Get every check.
     *
     * @return list<Check>
     */
    public function all(): array
    {
        return [
            $this->php(),
            $this->extensions(),
            $this->apiCore(),
            $this->env(),
            $this->configCache(),
            $this->node(),
            $this->nodeModules(),
            $this->build(),
            $this->mysql(),
            $this->migrations(Database::web(), 'Web migrations'),
            $this->electron(),
            $this->sandbox(),
            $this->migrations(Database::desktop($this->toolchain), 'Desktop migrations'),
            $this->docker(),
            $this->program('ngrok', 'For the phone over ngrok: snap install ngrok, then ngrok config add-authtoken <token>'),
            $this->program('pdftoppm', 'For drawing the invoice styles\' thumbnails: sudo apt install poppler-utils'),
        ];
    }

    /**
     * Get the checks, which the desktop app's window needs: Node, Electron and, on Linux, its sandbox.
     *
     * @return list<Check>
     */
    public function desktop(): array
    {
        return [$this->node(), $this->electron(), $this->sandbox()];
    }

    /**
     * Get the state of the SPA's build: `fresh`, `stale` (older than a source) or `missing`.
     *
     * @return string
     */
    public function buildState(): string
    {
        $manifest = public_path('build/manifest.json');

        if (!is_file($manifest)) {
            return 'missing';
        }

        $built = filemtime($manifest);
        $sources = [resource_path('js'), resource_path('css'), base_path('package.json'), base_path('vite.config.js')];

        foreach ($sources as $source) {
            $files = is_dir($source)
                ? new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS))
                : [new SplFileInfo($source)];

            foreach ($files as $file) {
                if ($file->isFile() && $file->getMTime() > $built) {
                    return 'stale';
                }
            }
        }

        return 'fresh';
    }

    /**
     * Check the running PHP's version.
     *
     * @return Check
     */
    public function php(): Check
    {
        return PHP_VERSION_ID >= 80500
            ? Check::ok('PHP', PHP_VERSION . ' (' . PHP_BINARY . ')')
            : Check::fail('PHP', PHP_VERSION, 'PryTax needs PHP 8.5: run ./prytax, which finds php8.5, or set PRYTAX_PHP');
    }

    /**
     * Check PHP's extensions.
     *
     * @return Check
     */
    public function extensions(): Check
    {
        $missing = array_values(array_filter(self::EXTENSIONS, fn (string $extension) => !extension_loaded($extension)));

        return $missing === []
            ? Check::ok('PHP extensions', implode(', ', self::EXTENSIONS))
            : Check::fail('PHP extensions', 'missing: ' . implode(', ', $missing), 'Install them, such as sudo apt install php8.5-' . $missing[0]);
    }

    /**
     * Check that api-core is there, through its link in vendor/ (Composer's path repository, `../api-core`).
     *
     * @return Check
     */
    public function apiCore(): Check
    {
        $path = realpath(base_path('vendor/andriichello/api-core'));

        return $path !== false
            ? Check::ok('api-core', $path)
            : Check::fail('api-core', 'vendor/andriichello/api-core leads nowhere', 'git clone https://github.com/Andriichello/api-core.git ../api-core, then composer install');
    }

    /**
     * Check `.env` and its application key.
     *
     * @return Check
     */
    public function env(): Check
    {
        if (!is_file(base_path('.env'))) {
            return Check::fail('.env', 'missing', 'cp .env.example .env, then php artisan key:generate');
        }

        return filled(config('app.key'))
            ? Check::ok('.env', 'with APP_KEY, APP_URL ' . config('app.url'))
            : Check::fail('.env', 'APP_KEY is empty', 'php artisan key:generate');
    }

    /**
     * Check that the configuration isn't cached, as the starter points its processes at a database by variables.
     *
     * @return Check
     */
    public function configCache(): Check
    {
        return app()->configurationIsCached()
            ? Check::warn('Config cache', 'cached: the starter\'s database choices would be ignored', 'php artisan config:clear')
            : Check::ok('Config cache', 'not cached');
    }

    /**
     * Check the Node, which the starter's processes run.
     *
     * @return Check
     */
    public function node(): Check
    {
        $version = $this->toolchain->nodeVersion();
        $major = (int) strtok((string) $version, '.');

        return match (true) {
            $version === null => Check::fail('Node', 'not found', 'Install Node 24, such as nvm install 24'),
            $major >= Toolchain::NODE_MAJOR => Check::ok('Node', $version),
            version_compare($version, '20.19', '>=') => Check::warn('Node', "$version: the web app's build, not the desktop app", 'nvm install 24'),
            default => Check::fail('Node', "$version: too old for Vite", 'nvm install 24'),
        };
    }

    /**
     * Check the SPA's packages.
     *
     * @return Check
     */
    public function nodeModules(): Check
    {
        return is_file(base_path('node_modules/.bin/vite'))
            ? Check::ok('SPA packages', 'node_modules')
            : Check::fail('SPA packages', 'not installed', 'npm install');
    }

    /**
     * Check the SPA's build.
     *
     * @return Check
     */
    public function build(): Check
    {
        return match ($this->buildState()) {
            'fresh' => Check::ok('SPA build', 'up to date'),
            'stale' => Check::warn('SPA build', 'older than its sources', 'npm run build (the starter offers it)'),
            default => Check::warn('SPA build', 'missing', 'npm run build (the starter offers it)'),
        };
    }

    /**
     * Check that the web app's MySQL answers, when `.env` uses MySQL.
     *
     * @return Check
     */
    public function mysql(): Check
    {
        $connection = (string) config('database.default');

        if ($connection !== 'mysql') {
            return Check::ok('MySQL', ".env uses $connection");
        }

        try {
            $version = DB::connection('mysql')->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION);
        } catch (Throwable $exception) {
            return Check::warn('MySQL', strtok($exception->getMessage(), "\n"), 'sudo systemctl start mysql, or ./prytax web --db=sqlite');
        }

        return Check::ok('MySQL', $version . ' at ' . config('database.connections.mysql.host'));
    }

    /**
     * Check a database's migrations.
     *
     * @param Database $database
     * @param string $name
     *
     * @return Check
     */
    public function migrations(Database $database, string $name): Check
    {
        if ($database->isNew()) {
            return Check::ok($name, 'no database yet: the starter makes it');
        }

        $pending = $database->pending($this->toolchain);

        return match (true) {
            $pending === null => Check::warn($name, "can't tell: $database->label doesn't answer", 'Start its server, or see ./prytax db'),
            $pending === [] => Check::ok($name, 'all ran'),
            default => Check::warn($name, count($pending) . ' pending: ' . implode(', ', $pending), './prytax db, or the starter offers them'),
        };
    }

    /**
     * Check NativePHP's Electron.
     *
     * @return Check
     */
    public function electron(): Check
    {
        return is_file($this->electronPath('electron'))
            ? Check::ok('Electron', 'installed')
            : Check::fail('Electron', 'not installed', 'php artisan native:install --force --no-interaction (the starter offers it)');
    }

    /**
     * Check Electron's sandbox helper on Linux, which must belong to root with the setuid bit, again after every
     * `native:install` or `native:build`.
     *
     * @return Check
     */
    public function sandbox(): Check
    {
        $sandbox = $this->electronPath('chrome-sandbox');

        if (PHP_OS_FAMILY !== 'Linux' || !is_file($sandbox)) {
            return Check::ok('Electron sandbox', PHP_OS_FAMILY === 'Linux' ? 'not installed yet' : 'not needed');
        }

        return fileowner($sandbox) === 0 && (fileperms($sandbox) & 07777) === 04755
            ? Check::ok('Electron sandbox', 'root, 4755')
            : Check::fail('Electron sandbox', 'not root with 4755', $this->sandboxFix());
    }

    /**
     * Get the commands, which fix the sandbox helper.
     *
     * @return string
     */
    public function sandboxFix(): string
    {
        $sandbox = $this->electronPath('chrome-sandbox');

        return "sudo chown root:root $sandbox && sudo chmod 4755 $sandbox";
    }

    /**
     * Check Docker with its compose plugin.
     *
     * @return Check
     */
    public function docker(): Check
    {
        $result = Process::run(['docker', 'compose', 'version', '--short']);

        if ($result->failed()) {
            return Check::warn('Docker', 'docker compose not found', 'For the Docker web app: install Docker with its compose plugin');
        }

        return is_file(base_path('.env.docker'))
            ? Check::ok('Docker', 'compose ' . trim($result->output()) . ', .env.docker')
            : Check::warn('Docker', 'compose ' . trim($result->output()) . ', no .env.docker', './prytax docker makes it');
    }

    /**
     * Check an optional program.
     *
     * @param string $program
     * @param string $hint
     *
     * @return Check
     */
    public function program(string $program, string $hint): Check
    {
        return $this->toolchain->has($program)
            ? Check::ok($program, 'installed')
            : Check::warn($program, 'not found', $hint);
    }

    /**
     * Get the path of a file of NativePHP's Electron.
     *
     * @param string $file
     *
     * @return string
     */
    protected function electronPath(string $file): string
    {
        return base_path("vendor/nativephp/desktop/resources/electron/node_modules/electron/dist/$file");
    }
}
