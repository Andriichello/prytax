<?php

/*
 * Backups and data retention (docs/specs/backups.md): the module's own settings. The app binds its registry, tenant
 * root, checks and retention handlers in PryTax\Providers\DataLifecycleBindings.
 */
return [
    /*
     * Version of the app, which a manifest names.
     */
    'app_version' => env('APP_VERSION', 'dev'),

    /*
     * Connection, on which a run reads its snapshot (backups.md §4.3): one of its own, or a replica, so the run's
     * writes never happen inside it. Null takes the default connection, as tests do, whose data is visible only
     * inside the test's transaction.
     */
    'connection' => env('BACKUP_DB_CONNECTION'),

    /*
     * The committed column snapshot of the registry, which `prytax:backup:snapshot` writes and a test compares.
     */
    'snapshot_path' => base_path('tests/Backup/registry.snapshot.json'),

    'backups' => [
        // Disk of the archives (`prytax-backups-<env>` in production), and the queue of the runs
        'disk' => env('BACKUPS_DISK', 'backups'),
        'queue' => env('BACKUPS_QUEUE', 'backups'),
        // Minutes, for which a download link works (§3.3)
        'url_lifetime' => 10,
        // Manual runs of an organization per day, and the manual backups it keeps (§2.2)
        'manual_per_day' => 5,
        'manual_cap' => 20,
        // Days, for which automatic (safety) backups are kept
        'automatic_days' => 30,
        // Hours, for which an organization's bundle or a period export is kept (§2.4, §2.7)
        'temporary_hours' => 24,
    ],
];
