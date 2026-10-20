<?php

use Core\Models\Organization;
use Core\Models\User;
use Spatie\Permission\Models\Role;

/*
 * Merged into api-core's config recursively, so this file holds only what
 * PryTax changes. Lists, such as `roles`, replace api-core's as a whole.
 *
 * @see vendor/andriichello/api-core/docs/getting-started/03-configuration.md
 */
return [
    /*
     * Roles from the highest to the lowest, below the built-in `super-admin`.
     * Every user of an organization sees every profile of it (design.md §0).
     * Run `php artisan db:seed --class="Core\Database\Seeders\RolesAndPermissionsSeeder"`
     * after changing them.
     *
     * @see vendor/andriichello/api-core/docs/roles/01-configuring-roles.md
     */
    'roles' => [
        [
            'name' => 'owner',
            'permissions' => [
                User::class => ['all', 'attachRole', 'attachAnyRole', 'detachRole'],
                Organization::class => ['viewAny', 'view', 'update'],
                Role::class => ['attachUser', 'attachAnyUser', 'detachUser'],
            ],
        ],
        [
            'name' => 'accountant',
            'permissions' => [
                User::class => ['viewAny', 'view'],
                Organization::class => ['viewAny', 'view'],
            ],
        ],
        [
            'name' => 'viewer',
            'permissions' => [
                User::class => ['viewAny', 'view'],
                Organization::class => ['viewAny', 'view'],
            ],
        ],
    ],
];
