<?php

use Core\Models\Organization;
use Core\Models\User;
use PryTax\DataLifecycle\Backup\Models\Backup;
use PryTax\DataLifecycle\Backup\Models\BackupPolicy;
use PryTax\DataLifecycle\Backup\Models\BackupRun;
use PryTax\Models\Account;
use PryTax\Models\AccountMovement;
use PryTax\Models\AccountOpeningBalance;
use PryTax\Models\AccountTransfer;
use PryTax\Models\Alert;
use PryTax\Models\ChargeType;
use PryTax\Models\Client;
use PryTax\Models\Contract;
use PryTax\Models\EsvObligation;
use PryTax\Models\ExchangeRate;
use PryTax\Models\Invoice;
use PryTax\Models\Payment;
use PryTax\Models\Profile;
use PryTax\Models\Project;
use PryTax\Models\Refund;
use PryTax\Models\TaxOpeningBalance;
use PryTax\Models\TaxPayment;
use PryTax\Models\TaxPaymentRequisite;
use PryTax\Models\TaxPeriod;
use Spatie\Permission\Models\Role;

/*
 * Models of a profile, on which owners and accountants do every financial operation, and viewers only read.
 */
$finance = [
    Account::class => ['all'],
    AccountOpeningBalance::class => ['all'],
    AccountMovement::class => ['all'],
    Client::class => ['all'],
    Contract::class => ['all'],
    Project::class => ['all'],
    ChargeType::class => ['all'],
    Invoice::class => ['all', 'freeze', 'unfreeze', 'send', 'pay', 'cancel', 'issue'],
    Payment::class => ['all', 'acknowledge'],
    Refund::class => ['all'],
    AccountTransfer::class => ['all'],
    TaxPeriod::class => ['viewAny', 'view', 'update'],
    TaxPayment::class => ['all'],
    TaxOpeningBalance::class => ['all'],
    TaxPaymentRequisite::class => ['all'],
    EsvObligation::class => ['viewAny', 'view'],
    Alert::class => ['viewAny', 'view', 'read'],
    ExchangeRate::class => ['viewAny', 'view'],
];

/*
 * Backups of the organization's profiles (docs/specs/backups.md D7): owners configure them, owners and accountants
 * start and download them; viewers have no access (a bulk export of personal data).
 */
$backups = [
    BackupPolicy::class => ['viewAny'],
    BackupRun::class => ['viewAny', 'view', 'create', 'bundle', 'export'],
    Backup::class => ['viewAny', 'view', 'download'],
];

$readOnly = array_map(fn (array $actions) => ['viewAny', 'view'], $finance);
$readOnly[Alert::class] = ['viewAny', 'view', 'read'];

/*
 * Merged into api-core's config recursively, so this file holds only what
 * PryTax changes. Lists, such as `roles`, replace api-core's as a whole.
 *
 * @see vendor/andriichello/api-core/docs/getting-started/03-configuration.md
 */
return [
    /*
     * Roles from the highest to the lowest, below the built-in `super-admin`.
     * Every user of an organization sees every profile of it (design.md §0):
     * owners manage everything, including profiles and users, accountants do
     * every financial operation of every profile, and viewers only read.
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
                Profile::class => ['all', 'restore', 'configure'],
                ...$finance,
                ...$backups,
                BackupPolicy::class => ['viewAny', 'configure'],
                // Restoring a profile from its archive, as a new profile of the organization
                Backup::class => ['viewAny', 'view', 'download', 'delete', 'import'],
            ],
        ],
        [
            'name' => 'accountant',
            'permissions' => [
                User::class => ['viewAny', 'view'],
                Organization::class => ['viewAny', 'view'],
                // Settings and signatures of profiles, not their identity, which owners keep
                Profile::class => ['viewAny', 'view', 'configure'],
                ...$finance,
                ...$backups,
            ],
        ],
        [
            'name' => 'viewer',
            'permissions' => [
                User::class => ['viewAny', 'view'],
                Organization::class => ['viewAny', 'view'],
                Profile::class => ['viewAny', 'view'],
                ...$readOnly,
            ],
        ],
    ],

    /*
     * The API answers in the signed-in user's own language (the account's `locale`, which the SPA's language switch
     * changes with `PATCH auth/me`), and before signing in in the language the request asks for (`Accept-Language`,
     * which the SPA sets to the language of its screens). Ukrainian is `lang/uk`; English is `app.locale`, so a request
     * without either gets it.
     *
     * @see vendor/andriichello/api-core/docs/localization/00-locale-middleware.md
     */
    'locales' => ['uk', 'en'],

    /*
     * Uploads of invoices (external PDFs) and of signatures.
     */
    'files' => [
        'extensions' => ['pdf', 'png', 'jpg', 'jpeg'],
        // Minutes, for which a signed URL of a file works (design.md §10)
        'url_lifetime' => 15,
    ],
];
