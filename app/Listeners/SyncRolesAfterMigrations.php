<?php

namespace PryTax\Listeners;

use Core\Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Artisan;

/**
 * Class SyncRolesAfterMigrations.
 *
 * The roles and their permissions of the desktop app (APP-BUILDS-PLAN.md, phase 3), which nobody seeds by hand: NativePHP
 * migrates the database on the first launch and after each update, and the roles follow, so the first owner can
 * register, and an update's new permissions reach the existing roles. A server seeds them on deploy instead.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Listeners
 */
class SyncRolesAfterMigrations
{
    /**
     * Sync the roles and their permissions with `api-core.roles`.
     *
     * @return void
     */
    public function handle(): void
    {
        Artisan::call('db:seed', ['--class' => RolesAndPermissionsSeeder::class, '--force' => true]);
    }
}
