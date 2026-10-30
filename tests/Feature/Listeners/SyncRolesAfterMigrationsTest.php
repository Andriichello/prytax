<?php

namespace Tests\Feature\Listeners;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PryTax\Listeners\SyncRolesAfterMigrations;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Class SyncRolesAfterMigrationsTest.
 *
 * The desktop app's roles, which follow NativePHP's migrations (builds.md §4.2): a new installation has
 * none until then, and the first owner couldn't register.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Listeners
 */
class SyncRolesAfterMigrationsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that a database without roles gets them, with their permissions.
     *
     * @return void
     */
    public function testSeedsTheRolesOfANewInstallation(): void
    {
        Role::query()->delete();

        app(SyncRolesAfterMigrations::class)->handle();

        $this->assertEqualsCanonicalizing(['super-admin', 'owner', 'accountant', 'viewer'], Role::query()->pluck('name')->all());
        $this->assertTrue(Role::findByName('owner')->hasPermissionTo('create models.invoices'));
    }
}
