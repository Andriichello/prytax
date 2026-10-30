<?php

namespace Tests\Feature\Http\Controllers;

use Tests\TestCase;

/**
 * Class EnvironmentControllerTest.
 *
 * Where the SPA runs (builds.md §4.2): the desktop app's window, when NativePHP runs PryTax.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Http\Controllers
 */
class EnvironmentControllerTest extends TestCase
{
    /**
     * Test that the SPA learns whether it runs in the desktop app.
     *
     * @return void
     */
    public function testTellsWhetherTheSpaRunsInTheDesktopApp(): void
    {
        $this->getJson('/api/environment')->assertOk()->assertJsonPath('data', ['desktop' => false]);

        config(['nativephp-internal.running' => true]);

        $this->getJson('/api/environment')->assertOk()->assertJsonPath('data', ['desktop' => true]);
    }
}
