<?php

namespace Tests\Feature\Console\Commands;

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PryTax\Console\Starter\Database;
use PryTax\Console\Starter\Doctor;
use PryTax\Console\Starter\Listeners;
use PryTax\Console\Starter\StarterState;
use PryTax\Console\Starter\Toolchain;
use Tests\TestCase;

/**
 * Class StartTest.
 *
 * PryTax's starter (builds.md §6), with its processes faked: the menu's platforms, tools, Back and Quit, what runs
 * told on start, the web app on a new SQLite file with the demo data and ready for a tunnel, a port in use refused
 * or the web app there stopped, the desktop window with this PHP and without DevTools, Docker with settings it makes
 * and a tunnel to it, a tunnel's address for a phone, starting a database over only when its name is typed and after
 * a copy, what runs told by `ss`, the last app started remembered and offered again with its overview and dates,
 * and, in the starter's container, only what runs there.
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
     * Keep the starters' record in a file of the test's own, never the project's.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(StarterState::class, new StarterState($this->temporaryFile()));
    }

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
     * Test that Back goes to the question asked before it, past one, which asked nothing (no pending migrations), and
     * from the first one to the menu it was chosen in, without starting anything.
     *
     * @return void
     */
    public function testGoesBackAQuestionAtATimeThenToTheMenu(): void
    {
        Process::fake();

        $this->artisan('prytax:start')
            ->expectsQuestion('Which platform?', 'web')
            ->expectsQuestion('Where should the web app run?', 'web')
            ->expectsQuestion('Which database?', 'mysql')
            ->expectsQuestion('How should the SPA be served?', 'back')
            ->expectsQuestion('Which database?', 'back')
            ->expectsQuestion('Where should the web app run?', 'back')
            ->expectsQuestion('Which platform?', 'quit')
            ->assertSuccessful();

        Process::assertDidntRun(fn (PendingProcess $process) => in_array('serve', (array) $process->command, true));
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
            ->expectsQuestion("The SQLite file $file is new. Add the demo data?", 'yes')
            ->expectsQuestion('How should the SPA be served?', 'no')
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
     * Test that the web app started is remembered: its choice, its SQLite file, when it was set up and when its
     * database was created, as its overview tells.
     *
     * @return void
     */
    public function testRemembersTheWebAppStartedWithItsDatabasesDate(): void
    {
        $this->travelTo('2026-10-31 18:02:00');
        $file = $this->temporaryFile();
        Process::fake([
            "*'migrate' '--force' '--seed'*" => function () use ($file) {
                file_put_contents($file, 'migrated');

                return Process::result();
            },
            '*prytax:summary*' => Process::result($this->summary('2026-10-31T18:01:00+02:00')),
            '*' => Process::result(),
        ]);
        $this->built();

        $this->artisan('prytax:start', ['action' => 'web', '--db' => 'sqlite', '--sqlite' => $file])
            ->expectsQuestion("The SQLite file $file is new. Add the demo data?", 'no')
            ->expectsQuestion('How should the SPA be served?', 'no')
            ->assertSuccessful();

        $last = app(StarterState::class)->last();
        $this->assertSame('web-here-sqlite', $last['choice']);
        $this->assertSame($file, $last['sqlite']);
        $this->assertSame('prytax', $last['run_by']);
        $this->assertSame('2026-10-31T18:02:00+02:00', $last['set_up_at']);
        $this->assertSame('2026-10-31T18:01:00+02:00', $last['database_created_at']);
    }

    /**
     * Test that the menu shows the last app started with its overview and dates, and starts it again the same way.
     *
     * @return void
     */
    public function testOffersTheLastAppAgainWithItsOverviewAndDates(): void
    {
        $this->travelTo('2026-10-01 09:00:00');
        $file = $this->temporaryFile();
        file_put_contents($file, 'migrated');
        app(StarterState::class)->remember('web-here-sqlite', $file, '2026-09-30T12:00:00+03:00');
        $this->travelTo('2026-10-31 18:02:00');
        Process::fake(['*prytax:summary*' => Process::result($this->summary('2026-09-30T12:00:00+03:00')), '*' => Process::result()]);
        $this->built();

        $this->artisan('prytax:start')
            ->expectsOutputToContain("Last: Web · Here · SQLite ($file), 01.10.2026 09:00 by ./prytax")
            ->expectsOutputToContain('Практика Коваленко (1 user)')
            ->expectsOutputToContain('Set up 01.10.2026 · database created 30.09.2026')
            ->expectsQuestion('Which platform?', 'again')
            ->expectsQuestion('How should the SPA be served?', 'no')
            ->expectsQuestion('Which platform?', 'quit')
            ->assertSuccessful();

        Process::assertRan(fn (PendingProcess $process) => in_array('serve', $process->command, true) && $process->environment['DB_DATABASE'] === $file);
    }

    /**
     * Test that the starter's container offers only the web app, here, and the tools, and the web app here again.
     *
     * @return void
     */
    public function testOffersOnlyTheWebAppHereInTheContainer(): void
    {
        Process::fake();
        $this->built();
        $this->partialMock(Toolchain::class, fn (MockInterface $mock) => $mock->shouldReceive('inContainer')->andReturn(true));

        $this->artisan('prytax:start')
            ->expectsQuestion('Which platform?', 'web')
            ->expectsQuestion('Which database?', 'mysql')
            ->expectsQuestion('How should the SPA be served?', 'no')
            ->expectsChoice('Which platform?', 'quit', [
                'again' => 'Again — Web · Here · MySQL',
                'web' => 'Web — here',
                'tools' => 'Tools — status, doctor, tests, builds, database',
                'quit' => 'Quit',
            ])
            ->assertSuccessful();

        Process::assertRan(fn (PendingProcess $process) => in_array('serve', $process->command, true) && $process->environment['DB_CONNECTION'] === 'mysql');
    }

    /**
     * Test that an action, which needs this machine, is refused in the starter's container, saying why.
     *
     * @param string $action
     * @param string $why
     *
     * @return void
     */
    #[DataProvider('hostActions')]
    public function testRefusesTheHostsActionsInTheContainer(string $action, string $why): void
    {
        Process::fake();
        $this->partialMock(Toolchain::class, fn (MockInterface $mock) => $mock->shouldReceive('inContainer')->andReturn(true));

        $this->artisan('prytax:start', ['action' => $action])
            ->expectsOutputToContain($why)
            ->assertFailed();

        Process::assertNothingRan();
    }

    /**
     * Test that the starter's container refuses a SQLite file outside the checkout, which it can't see.
     *
     * @return void
     */
    public function testRefusesASqliteFileOutsideTheCheckoutInTheContainer(): void
    {
        Process::fake();
        $this->partialMock(Toolchain::class, fn (MockInterface $mock) => $mock->shouldReceive('inContainer')->andReturn(true));

        $this->artisan('prytax:start', ['action' => 'web', '--db' => 'sqlite', '--sqlite' => '/srv/elsewhere.sqlite'])
            ->expectsOutputToContain('/srv/elsewhere.sqlite is outside the checkout')
            ->assertFailed();

        $this->assertFileDoesNotExist('/srv/elsewhere.sqlite');
        Process::assertDidntRun(fn (PendingProcess $process) => in_array('migrate', $process->command, true));
    }

    /**
     * Test that Tools → Claude prints the connection of the desktop app's integration and asks its server, as Claude
     * would, telling its tools and the ФОП it reads.
     *
     * @return void
     */
    public function testPrintsClaudesConnectionAndAsksTheServer(): void
    {
        $connection = [
            'organization' => 'Практика Коваленко', 'name' => 'prytax', 'command' => PHP_BINARY, 'args' => [base_path('artisan'), 'mcp:start', 'prytax'],
            'env' => ['PRYTAX_MCP_KEY' => 'prytax_key'], 'claude_code' => 'claude mcp add --scope user prytax -e PRYTAX_MCP_KEY=prytax_key -- php artisan mcp:start prytax',
            'claude_desktop' => '{"mcpServers":{}}', 'notes' => [], 'context' => 'local',
        ];
        $answers = implode("\n", [
            json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['serverInfo' => ['name' => 'PryTax']]]),
            json_encode(['jsonrpc' => '2.0', 'id' => 2, 'result' => ['tools' => array_fill(0, 13, ['name' => 'tool'])]]),
            // Unescaped, as the server writes it: «х» is D1 85 in UTF-8
            json_encode(['jsonrpc' => '2.0', 'id' => 3, 'result' => ['structuredContent' => ['profiles' => [['full_name' => 'Михайленко Олена Петрівна']]]]], JSON_UNESCAPED_UNICODE),
        ]);
        Process::fake([
            '*prytax:claude-connection*' => Process::result(json_encode([$connection])),
            "*'mcp:start'*" => Process::result($answers),
            '*' => Process::result(),
        ]);
        $directory = $this->temporaryFile();
        mkdir($directory);
        file_put_contents("$directory/nativephp.sqlite", 'migrated');
        $this->app->useDatabasePath($directory);

        $this->artisan('prytax:start', ['action' => 'mcp'])
            ->expectsQuestion('Whose data should Claude read?', 'desktop')
            ->expectsOutputToContain('claude mcp add --scope user prytax -e PRYTAX_MCP_KEY=prytax_key')
            ->expectsOutputToContain("PryTax's server answers: 13 tools, reading Михайленко Олена Петрівна.")
            ->assertSuccessful();

        Process::assertRan(fn (PendingProcess $process) => in_array('--desktop', $process->command, true)
            && $process->environment['DB_DATABASE'] === "$directory/nativephp.sqlite");
        Process::assertRan(fn (PendingProcess $process) => in_array('mcp:start', $process->command, true)
            && $process->environment['PRYTAX_MCP_KEY'] === 'prytax_key'
            && str_contains((string) $process->input, '"name":"prytax-starter"'));
    }

    /**
     * Test that Tools → Claude offers the data of the app started last first, the web app here on its SQLite file,
     * and reads Claude's connection from it.
     *
     * @return void
     */
    public function testOffersTheLastAppsDataToClaudeFirst(): void
    {
        $file = $this->temporaryFile();
        file_put_contents($file, 'migrated');
        app(StarterState::class)->remember('web-here-sqlite', $file);
        Process::fake(['*prytax:claude-connection*' => Process::result('[]', exitCode: 1), '*' => Process::result()]);

        $this->artisan('prytax:start', ['action' => 'mcp'])
            ->expectsChoice('Whose data should Claude read?', 'last', [
                'last' => "The web app's here — $file",
                'desktop' => "The desktop app's — database/nativephp.sqlite",
                'web' => ucfirst(Database::web()->label),
                'sqlite' => 'Another SQLite file',
                'quit' => 'Quit',
            ])
            ->expectsOutputToContain("Claude isn't on in the SQLite file $file.")
            ->assertFailed();

        Process::assertRan(fn (PendingProcess $process) => in_array('prytax:claude-connection', $process->command, true)
            && $process->environment['DB_DATABASE'] === $file);
    }

    /**
     * Get the actions, which need this machine, with what the refusal says.
     *
     * @return array<string, array{string, string}>
     */
    public static function hostActions(): array
    {
        return [
            'the desktop window' => ['desktop', 'The desktop app\'s window needs PHP 8.5 on this machine'],
            'Docker' => ['docker', './start runs PryTax in Docker without PHP'],
            'the phone over ngrok' => ['phone', 'The phone over ngrok needs PHP 8.5 and ngrok on this machine'],
            'Claude' => ['mcp', 'Claude starts PryTax\'s server with PHP 8.5 on this machine'],
        ];
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
     * Test that Docker's settings are made when they're missing, on SQLite by default, with a key of their own, and
     * that compose runs with them, without MySQL, as one person's installation.
     *
     * @return void
     */
    public function testStartsDockerOnSqliteByDefaultWithTheSettingsItMakes(): void
    {
        Process::fake();
        $file = $this->temporaryFile();

        $this->artisan('prytax:start', ['action' => 'docker', '--env-file' => $file])
            ->expectsQuestion("There's no $file. Make it from .env.docker.example, with a new APP_KEY?", 'yes')
            ->expectsQuestion('Which database?', 'sqlite')
            ->expectsQuestion('PryTax runs in Docker at http://localhost:8080.', 'quit')
            ->assertSuccessful();

        $settings = (string) file_get_contents($file);
        $this->assertStringContainsString("PRYTAX_ENV_FILE=$file", $settings);
        $this->assertMatchesRegularExpression('/^APP_KEY=base64:\S{44}$/m', $settings);
        $this->assertMatchesRegularExpression('/^DB_CONNECTION=sqlite$/m', $settings);
        Process::assertRan(fn (PendingProcess $process) => $process->command === ['docker', 'compose', '--env-file', $file, 'up', '-d', '--build']
            && $process->environment['PRYTAX_SINGLE_ORGANIZATION'] === 'true');
    }

    /**
     * Test that `--mysql` makes the settings MySQL's, with a password of their own, and runs compose with its MySQL.
     *
     * @return void
     */
    public function testStartsDockerWithItsMysqlWhenAsked(): void
    {
        Process::fake();
        $file = $this->temporaryFile();

        $this->artisan('prytax:start', ['action' => 'docker', '--env-file' => $file, '--mysql' => true])
            ->expectsQuestion("There's no $file. Make it from .env.docker.example, with a new APP_KEY?", 'yes')
            ->expectsQuestion('PryTax runs in Docker at http://localhost:8080.', 'quit')
            ->assertSuccessful();

        $settings = (string) file_get_contents($file);
        $this->assertMatchesRegularExpression('/^DB_CONNECTION=mysql$/m', $settings);
        $this->assertDoesNotMatchRegularExpression('/^DB_PASSWORD=change-me$/m', $settings);
        Process::assertRan(fn (PendingProcess $process) => $process->command === ['docker', 'compose', '--env-file', $file, '--profile', 'mysql', 'up', '-d', '--build']
            && $process->environment['PRYTAX_SINGLE_ORGANIZATION'] === 'true');
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
     * Get an overview (`prytax:summary --json`) of one organization with one ФОП, of the database created then.
     *
     * @param string $createdAt
     *
     * @return string
     */
    private function summary(string $createdAt): string
    {
        $usage = ['count' => 0, 'bytes' => 0];
        $totals = ['documents' => $usage, 'backups' => $usage, 'latest_backup_at' => null];

        return json_encode([
            'database' => ['driver' => 'sqlite', 'name' => 'prytax.sqlite', 'bytes' => 1024, 'created_at' => $createdAt],
            'organizations' => [[
                'id' => 1, 'name' => 'Практика Коваленко', 'users' => 1, ...$totals,
                'profiles' => [['id' => 1, 'name' => 'Коваленко О. П.', 'closed' => false, ...$totals]],
            ]],
            'totals' => ['organizations' => 1, 'profiles' => 1, ...$totals],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
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
