<?php

namespace Tests\Feature\Console\Commands;

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use PDO;
use PryTax\Console\Starter\Doctor;
use PryTax\Console\Starter\Listeners;
use Tests\TestCase;

/**
 * Class StartTest.
 *
 * PryTax's starter (builds.md §6), with its processes faked: the menu's platforms, tools, Back and Quit, what runs
 * told on start, the web app on a new SQLite file with the demo data and ready for a tunnel, a port in use refused
 * or the web app there stopped, the desktop window with this PHP and without DevTools, Docker with settings it makes
 * and a tunnel to it, a tunnel's address for a phone, starting a database over only when its name is typed and after
 * a copy, and what runs told by `ss`.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Console\Commands
 */
class StartTest extends TestCase
{
    /**
     * The files the test made, which it removes.
     *
     * @var list<string>
     */
    private array $files = [];

    /**
     * Remove the test's files.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        foreach ($this->files as $pattern) {
            array_map('unlink', glob("$pattern/*") ?: []);
            array_map(fn (string $path) => is_dir($path) ? rmdir($path) : unlink($path), glob("$pattern*") ?: []);
        }

        parent::tearDown();
    }

    /**
     * Test that the menu asks for the platform first, tells what runs before, leads to the web app's places and to
     * the tools, goes back from them, and quits, without starting anything.
     *
     * @return void
     */
    public function testLeadsFromThePlatformsToTheToolsAndBackThenQuits(): void
    {
        Process::fake(["'ss' *" => Process::result('LISTEN 0 511 127.0.0.1:8100 0.0.0.0:* users:(("php",pid=4242,fd=10))'), '*' => Process::result()]);

        $this->artisan('prytax:start')
            ->expectsOutputToContain('○ Web app')
            ->expectsOutputToContain('● Desktop app')
            ->expectsQuestion('Which platform?', 'tools')
            ->expectsQuestion('Which tool?', 'back')
            ->expectsQuestion('Which platform?', 'web')
            ->expectsQuestion('Where should the web app run?', 'back')
            ->expectsQuestion('Which platform?', 'quit')
            ->assertSuccessful();

        Process::assertDidntRun(fn (PendingProcess $process) => ($process->command[0] ?? null) !== 'ss');
    }

    /**
     * Test that the web app makes a new SQLite file with the demo data, then runs its server, queue worker and
     * scheduler on it, letting a tunnel's address sign in, until they end.
     *
     * @return void
     */
    public function testStartsTheWebAppOnANewSqliteFile(): void
    {
        Process::fake();
        $this->built();
        $file = $this->temporaryFile();

        $this->artisan('prytax:start', ['action' => 'web', '--db' => 'sqlite', '--sqlite' => $file])
            ->expectsConfirmation('Add the demo data? (Практика Коваленко: sign in as olena@example.com / password)', 'yes')
            ->expectsConfirmation('Use Vite\'s hot reload? (No: the built SPA)', 'no')
            ->assertSuccessful();

        $port = Listeners::webPort();
        $this->assertFileExists($file);
        Process::assertRan(fn (PendingProcess $process) => $process->command === [PHP_BINARY, 'artisan', 'migrate', '--force', '--seed'] && $process->environment['DB_DATABASE'] === $file);
        Process::assertRan(fn (PendingProcess $process) => $process->command === [PHP_BINARY, 'artisan', 'db:seed', '--class=Database\Seeders\DemoSeeder', '--force']);
        Process::assertRan(fn (PendingProcess $process) => $process->command === [PHP_BINARY, 'artisan', 'serve', '--host=127.0.0.1', "--port=$port", '--no-reload']
            && $process->environment['DB_CONNECTION'] === 'sqlite'
            && str_ends_with($process->environment['SANCTUM_STATEFUL_DOMAINS'], ',__SANCTUM_CURRENT_REQUEST_HOST__')
            && $process->environment['TRUSTED_PROXIES'] === '127.0.0.1'
            && $process->environment['PRYTAX_SINGLE_ORGANIZATION'] === 'true');
        Process::assertRan(fn (PendingProcess $process) => $process->command === [PHP_BINARY, 'artisan', 'queue:work', '--queue=backups,retention,default'] && $process->environment['DB_DATABASE'] === $file);
        Process::assertRan(fn (PendingProcess $process) => $process->command === [PHP_BINARY, 'artisan', 'schedule:work']);
        Process::assertDidntRun(fn (PendingProcess $process) => str_ends_with((string) ($process->command[0] ?? ''), 'vite'));
    }

    /**
     * Test that the web app isn't started on a port, which something listens on, when nobody can be asked.
     *
     * @return void
     */
    public function testRefusesTheWebAppsPortInUseWithoutInteraction(): void
    {
        $port = Listeners::webPort();
        Process::fake(["'ss' *" => Process::result("LISTEN 0 511 127.0.0.1:$port 0.0.0.0:* users:((\"php8.5\",pid=4242,fd=4))")]);

        $this->artisan('prytax:start', ['action' => 'web', '--db' => 'mysql', '--no-interaction' => true])
            ->expectsOutputToContain("The web app needs port $port, which is in use.")
            ->assertFailed();

        Process::assertDidntRun(fn (PendingProcess $process) => in_array('serve', (array) $process->command, true));
    }

    /**
     * Test that the web app, which runs already, is stopped when that's chosen, rather than started again.
     *
     * @return void
     */
    public function testStopsTheWebAppThatRunsAlreadyWhenChosen(): void
    {
        config(['app.url' => 'http://localhost:8010']);
        $sleep = proc_open(['sleep', '30'], [], $pipes);
        $pid = proc_get_status($sleep)['pid'];
        Process::preventStrayProcesses();
        Process::fake(["'ss' *" => Process::result("LISTEN 0 511 127.0.0.1:8010 0.0.0.0:* users:((\"php8.5\",pid=$pid,fd=4))")]);

        $this->artisan('prytax:start', ['action' => 'web'])
            ->expectsQuestion('The web app runs already, at http://localhost:8010.', 'stop')
            ->expectsOutputToContain('Stopped: Web app.')
            ->assertSuccessful();

        for ($attempt = 0; $attempt < 50 && ($status = proc_get_status($sleep))['running']; $attempt++) {
            usleep(20_000);
        }

        proc_close($sleep);
        $this->assertSame(SIGTERM, $status['termsig']);
        Process::assertDidntRun(fn (PendingProcess $process) => in_array('serve', (array) $process->command, true));
    }

    /**
     * Test that the desktop window opens through `native:run`, with this PHP first on its PATH as `php`, without
     * DevTools unless asked, and with the DevTools protocol for scripts.
     *
     * @return void
     */
    public function testOpensTheDesktopWindowWithThisPhp(): void
    {
        Process::fake();
        $this->built(['desktop' => []]);
        $directory = $this->temporaryFile();
        mkdir($directory);
        file_put_contents("$directory/nativephp.sqlite", 'migrated');
        $this->app->useDatabasePath($directory);

        $this->artisan('prytax:start', ['action' => 'desktop'])->assertSuccessful();

        Process::assertRan(function (PendingProcess $process) {
            $directory = Str::before($process->environment['PATH'] ?? '', ':');

            return $process->command === [PHP_BINARY, 'artisan', 'native:run', '--no-dependencies', '--no-interaction', '-v']
                && $process->environment['PRYTAX_DEVTOOLS'] === 'false'
                && $process->environment['REMOTE_DEBUGGING_PORT'] === '9333'
                && $process->environment['PRYTAX_SINGLE_ORGANIZATION'] === 'true'
                && readlink("$directory/php") === PHP_BINARY;
        });
    }

    /**
     * Test that Docker's settings are made when they're missing, with a key and a database password of their own,
     * and that compose runs with them and its MySQL.
     *
     * @return void
     */
    public function testStartsDockerWithTheSettingsItMakes(): void
    {
        Process::fake();
        $file = $this->temporaryFile();

        $this->artisan('prytax:start', ['action' => 'docker', '--env-file' => $file, '--mysql' => true])
            ->expectsConfirmation("There's no $file. Make it from .env.docker.example, with a new APP_KEY and database password?", 'yes')
            ->expectsQuestion('PryTax runs in Docker at http://localhost:8080.', 'quit')
            ->assertSuccessful();

        $settings = (string) file_get_contents($file);
        $this->assertStringContainsString("PRYTAX_ENV_FILE=$file", $settings);
        $this->assertMatchesRegularExpression('/^APP_KEY=base64:\S{44}$/m', $settings);
        $this->assertDoesNotMatchRegularExpression('/^DB_PASSWORD=change-me$/m', $settings);
        Process::assertRan(fn (PendingProcess $process) => $process->command === ['docker', 'compose', '--env-file', $file, '--profile', 'mysql', 'up', '-d', '--build']);
    }

    /**
     * Test that a tunnel to Docker's app makes its container again with the tunnel's address signing in, then, once
     * the tunnel ends, as its settings say.
     *
     * @return void
     */
    public function testTunnelsToDockerWithItsAppLettingTheAddressSignIn(): void
    {
        $file = $this->temporaryFile();
        file_put_contents($file, "APP_URL=http://localhost:8099\nAPP_PORT=8099\nSANCTUM_STATEFUL_DOMAINS=localhost:8099\n");
        $override = storage_path('framework/prytax-tunnel.compose.yaml');
        $made = null;
        Process::fake([
            "'ss' *" => Process::result('LISTEN 0 4096 0.0.0.0:8099 0.0.0.0:*'),
            "'ngrok' *" => Process::result('{"lvl":"info","msg":"started tunnel","url":"https://abc.ngrok-free.app"}'),
            "'docker' *" => function (PendingProcess $process) use ($override, &$made) {
                if (in_array($override, (array) $process->command, true)) {
                    $made = file_get_contents($override);
                }

                return Process::result();
            },
            '*' => Process::result(),
        ]);
        $compose = ['docker', 'compose', '--env-file', $file, '-f', base_path('compose.yaml')];

        $this->artisan('prytax:start', ['action' => 'docker', '--env-file' => $file])
            ->expectsQuestion('PryTax runs in Docker at http://localhost:8099.', 'tunnel')
            ->expectsOutputToContain('Open https://abc.ngrok-free.app on the phone')
            ->expectsQuestion('PryTax runs in Docker at http://localhost:8099.', 'quit')
            ->assertSuccessful();

        $this->assertStringContainsString('SANCTUM_STATEFUL_DOMAINS: "localhost:8099,__SANCTUM_CURRENT_REQUEST_HOST__"', (string) $made);
        $this->assertStringContainsString('TRUSTED_PROXIES: PRIVATE_SUBNETS', (string) $made);
        $this->assertFileDoesNotExist($override);
        Process::assertRan(fn (PendingProcess $process) => $process->command === [...$compose, '-f', $override, 'up', '-d', 'app']);
        Process::assertRan(fn (PendingProcess $process) => $process->command === ['ngrok', 'http', '127.0.0.1:8099', '--log=stdout', '--log-format=json']);
        Process::assertRan(fn (PendingProcess $process) => $process->command === [...$compose, 'up', '-d', 'app']);
        Process::assertDidntRun(fn (PendingProcess $process) => in_array('--build', (array) $process->command, true));
    }

    /**
     * Test that a phone gets PHP's server on the desktop app's data behind a tunnel, whose address ngrok's log tells,
     * with that address signing in and registration off.
     *
     * @return void
     */
    public function testServesThePhoneBehindATunnelAndTellsItsAddress(): void
    {
        Process::fake([
            "'ngrok' *" => Process::result("{\"lvl\":\"info\",\"msg\":\"starting web service\"}\n{\"lvl\":\"info\",\"msg\":\"started tunnel\",\"url\":\"https://abc.ngrok-free.app\"}"),
            '*' => Process::result(),
        ]);
        $this->built();
        $directory = $this->temporaryFile();
        mkdir($directory);
        file_put_contents("$directory/nativephp.sqlite", 'migrated');
        $this->app->useDatabasePath($directory);

        $this->artisan('prytax:start', ['action' => 'phone', '--source' => 'desktop'])
            ->expectsOutputToContain('Open https://abc.ngrok-free.app on the phone, and sign in with an account of')
            ->doesntExpectOutputToContain('starting web service')
            ->assertSuccessful();

        Process::assertRan(fn (PendingProcess $process) => in_array('127.0.0.1:8012', (array) $process->command, true)
            && $process->environment['DB_DATABASE'] === "$directory/nativephp.sqlite"
            && str_ends_with($process->environment['SANCTUM_STATEFUL_DOMAINS'], ',__SANCTUM_CURRENT_REQUEST_HOST__')
            && $process->environment['TRUSTED_PROXIES'] === '127.0.0.1'
            && $process->environment['PRYTAX_REGISTRATION'] === 'false'
            && $process->environment['PRYTAX_SINGLE_ORGANIZATION'] === 'true');
        Process::assertRan(fn (PendingProcess $process) => $process->command === ['ngrok', 'http', '127.0.0.1:8012', '--log=stdout', '--log-format=json']);
    }

    /**
     * Test that a database is started over only when its name is typed, and only after a copy of its file.
     *
     * @return void
     */
    public function testStartsADatabaseOverOnlyOnceItsNameIsTyped(): void
    {
        Process::fake();
        $file = $this->temporaryFile();
        (new PDO("sqlite:$file"))->exec('CREATE TABLE notes (text TEXT); INSERT INTO notes VALUES (\'kept\')');
        $erase = "This erases everything in the SQLite file $file. Type «" . basename($file) . '» to go on';

        $this->artisan('prytax:start', ['action' => 'db'])
            ->expectsQuestion('Which database?', 'sqlite')
            ->expectsQuestion('Which SQLite file?', $file)
            ->expectsQuestion("What should be done to the SQLite file $file?", 'demo')
            ->expectsQuestion($erase, 'prytax')
            ->expectsOutputToContain('Nothing was erased.')
            ->assertFailed();

        Process::assertDidntRun(fn (PendingProcess $process) => in_array('migrate:fresh', (array) $process->command, true));
        $this->assertSame([$file], glob("$file*"));

        $this->artisan('prytax:start', ['action' => 'db'])
            ->expectsQuestion('Which database?', 'sqlite')
            ->expectsQuestion('Which SQLite file?', $file)
            ->expectsQuestion("What should be done to the SQLite file $file?", 'demo')
            ->expectsQuestion($erase, basename($file))
            ->assertSuccessful();

        $copy = glob("$file.before-demo-*")[0] ?? null;
        $this->assertNotNull($copy);
        $this->assertSame('kept', (new PDO("sqlite:$copy"))->query('SELECT text FROM notes')->fetchColumn());
        Process::assertRan(fn (PendingProcess $process) => $process->command === [PHP_BINARY, 'artisan', 'migrate:fresh', '--force', '--seed'] && $process->environment['DB_DATABASE'] === $file);
    }

    /**
     * Test that what listens is read from `ss`, and from `lsof` where there's no `ss`, with its processes.
     *
     * @return void
     */
    public function testTellsWhatListensFromSsOrLsof(): void
    {
        $ss = <<<'TEXT'
            LISTEN 0      511          127.0.0.1:8100       0.0.0.0:*    users:(("php",pid=79730,fd=10))
            LISTEN 0      10           127.0.0.1:9333       0.0.0.0:*    users:(("php",pid=79730,fd=67),("electron",pid=79598,fd=67))
            LISTEN 0      151          127.0.0.1:3306       0.0.0.0:*
            LISTEN 0      511              [::1]:5173          [::]:*    users:(("node",pid=5050,fd=21))
            TEXT;

        $this->assertSame([8100 => [79730], 9333 => [79730, 79598], 3306 => [], 5173 => [5050]], Listeners::parseSs($ss));
        $this->assertSame([8012 => [77], 4040 => [88]], Listeners::parseLsof("p77\nn127.0.0.1:8012\np88\nn127.0.0.1:4040"));
    }

    /**
     * Mock the doctor: the SPA built, and the desktop checks as given.
     *
     * @param array<string, mixed> $returns
     *
     * @return void
     */
    private function built(array $returns = []): void
    {
        $this->partialMock(Doctor::class, function (MockInterface $mock) use ($returns) {
            $mock->shouldReceive('buildState')->andReturn('fresh');

            foreach ($returns as $method => $value) {
                $mock->shouldReceive($method)->andReturn($value);
            }
        });
    }

    /**
     * Get the path of a file, which doesn't exist yet and is removed after the test, with its copies.
     *
     * @return string
     */
    private function temporaryFile(): string
    {
        return $this->files[] = sys_get_temp_dir() . '/prytax-start-test-' . Str::random(8);
    }
}
