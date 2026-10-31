<?php

namespace Tests\Feature\Console\Starter;

use Illuminate\Support\Str;
use PryTax\Console\Starter\StarterState;
use Tests\TestCase;

/**
 * Class StarterStateTest.
 *
 * What the starters remember between runs (builds.md §6): a choice's set-up date kept across its runs until its
 * settings or database are new, another SQLite file set up anew, and the lines `./start` writes read alike.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Console\Starter
 */
class StarterStateTest extends TestCase
{
    /**
     * The record's file of the test.
     *
     * @var string
     */
    private string $file;

    /**
     * Remove the test's file.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        @unlink($this->file);

        parent::tearDown();
    }

    /**
     * Test that a choice keeps the date it was set up across its runs, until it's forgotten.
     *
     * @return void
     */
    public function testKeepsTheSetUpDateAcrossRunsUntilForgotten(): void
    {
        $state = $this->state();
        $this->travelTo('2026-10-01 09:00:00');
        $state->remember('desktop', databaseCreatedAt: '2026-10-01T08:59:00+03:00');
        $this->travelTo('2026-10-31 18:02:00');
        $state->remember('desktop');

        $kept = $state->last();
        $state->forget('desktop');
        $forgotten = $state->last();

        $this->assertSame('2026-10-01T09:00:00+03:00', $kept['set_up_at']);
        $this->assertSame('2026-10-01T08:59:00+03:00', $kept['database_created_at']);
        $this->assertSame('2026-10-31T18:02:00+02:00', $kept['run_at']);
        $this->assertNull($forgotten['set_up_at']);
        $this->assertNull($forgotten['database_created_at']);
    }

    /**
     * Test that the web app here on another SQLite file is set up anew.
     *
     * @return void
     */
    public function testSetsUpAnotherSqliteFileAnew(): void
    {
        $state = $this->state();
        $this->travelTo('2026-10-01 09:00:00');
        $state->remember('web-here-sqlite', '/srv/first.sqlite');
        $this->travelTo('2026-10-31 18:02:00');

        $state->remember('web-here-sqlite', '/srv/second.sqlite');

        $this->assertSame('/srv/second.sqlite', $state->last()['sqlite']);
        $this->assertSame('2026-10-31T18:02:00+02:00', $state->last()['set_up_at']);
    }

    /**
     * Test that the lines `./start` writes are read: its offset without a colon, and quoted values.
     *
     * @return void
     */
    public function testReadsTheLinesStartWrites(): void
    {
        $state = $this->state();
        file_put_contents($this->file, implode("\n", [
            'LAST_RUN_AT=2026-10-31T18:02:11+0200',
            'LAST_RUN_BY=start',
            'LAST_CHOICE=web-docker-sqlite',
            'WEB_DOCKER_SQLITE_SET_UP_AT="2026-10-30T10:00:00+0200"',
            '',
        ]));

        $last = $state->last();

        $this->assertSame('Web · In Docker · SQLite', $last['label']);
        $this->assertSame('start', $last['run_by']);
        $this->assertSame('2026-10-31T18:02:11+0200', $last['run_at']);
        $this->assertSame('2026-10-30T10:00:00+0200', $last['set_up_at']);
    }

    /**
     * Get the record, in a file of the test's own.
     *
     * @return StarterState
     */
    private function state(): StarterState
    {
        return new StarterState($this->file = sys_get_temp_dir() . '/prytax-starter-test-' . Str::random(8) . '.env');
    }
}
