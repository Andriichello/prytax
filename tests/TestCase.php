<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

abstract class TestCase extends BaseTestCase
{
    /**
     * Seed the roles and their permissions, when a test refreshes the database: RefreshDatabase seeds only once, as
     * it migrates, so every test class asks for it.
     *
     * @var bool
     */
    protected bool $seed = true;

    /**
     * Set up the test environment: no request may reach the network, such as the NBU's rates, unless a test fakes
     * its endpoint, pages render without the SPA's built assets, and backups (a deleted profile's) go to a fake disk.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->withoutVite();
        Storage::fake('backups');
    }
}
