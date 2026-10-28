<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Class SpaRouteTest.
 *
 * The SPA's page answers the paths, which the API links to, while the API, the links of files and the health check
 * keep their own responses.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature
 */
class SpaRouteTest extends TestCase
{
    /**
     * Paths of the SPA, which notifications and e-mails link to, render the app's page.
     *
     * @return void
     */
    public function testRendersTheAppForPathsOfTheSpa(): void
    {
        foreach (['/', '/invoices/12', '/accounting/2026/4', '/reset-password?token=abc', '/apiary'] as $path) {
            $response = $this->get($path);

            $response->assertOk();
            $response->assertViewIs('app');
        }
    }

    /**
     * An unknown path of the API answers a JSON 404, not the app's page.
     *
     * @return void
     */
    public function testReturns404JsonForUnknownApiPaths(): void
    {
        foreach (['/api', '/api/unknown'] as $path) {
            $response = $this->get($path);

            $response->assertNotFound();
            $response->assertJsonStructure(['message']);
        }
    }

    /**
     * The links of files (an archive's download, a file of the local disk) keep their routes: without a signature
     * they are 403s, not the app's page.
     *
     * @return void
     */
    public function testLeavesTheLinksOfFilesToTheirRoutes(): void
    {
        foreach (['/backups-storage/tmp/downloads/copy.zip', '/storage/file.txt'] as $path) {
            $this->get($path)->assertForbidden();
        }
    }

    /**
     * The health check keeps its own route.
     *
     * @return void
     */
    public function testKeepsTheHealthCheck(): void
    {
        $response = $this->get('/up');

        $response->assertOk();
        $response->assertSee('Application up');
    }
}
