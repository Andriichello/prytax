<?php

namespace PryTax\Services\Installation;

/**
 * Class InstallationTables.
 *
 * How a full backup of the installation treats its tables (builds.md §4.11): those not compared (sessions, caches,
 * the queue, one-time tokens), those compared but not deciding whether a restore only moves this installation forward
 * (history, derived rows, caches and seeded rows), those emptied in a restored database, and the columns encrypted
 * with the installation's key, which a restore encrypts again with this installation's.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Services\Installation
 */
final class InstallationTables
{
    /**
     * Tables, which aren't compared; the migrations are compared as the schema.
     *
     * @var list<string>
     */
    public const array IGNORED = [
        'migrations', 'installations', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs',
        'password_reset_tokens', 'personal_access_tokens', 'idempotency_keys',
    ];

    /**
     * Tables, which are compared and shown, but don't decide the verdict: history, derived rows, caches and rows,
     * which the migrations and seeders make.
     *
     * @var list<string>
     */
    public const array NOT_COUNTED = [
        'audit_logs', 'alerts', 'alert_reads', 'notifications', 'exchange_rates', 'backup_runs', 'backups',
        'backup_downloads', 'data_retention_runs', 'data_retention_jobs', 'permissions', 'roles', 'role_has_permissions',
        'tax_rules', 'holidays',
    ];

    /**
     * Columns, which signing in writes: not compared, as using an installation isn't changing its data.
     *
     * @var array<string, list<string>>
     */
    public const array VOLATILE = ['users' => ['last_login_at', 'remember_token']];

    /**
     * Tables, whose times don't tell the data's last change: signing in touches a user.
     *
     * @var list<string>
     */
    public const array UNDATED = ['users'];

    /**
     * Tables, which a restored database has emptied: another computer's sessions, caches and queued jobs.
     *
     * @var list<string>
     */
    public const array CLEARED = ['sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'password_reset_tokens'];

    /**
     * Columns encrypted with the installation's key, by table, as their models cast them.
     *
     * @var array<string, list<string>>
     */
    public const array ENCRYPTED = [
        'profiles' => ['tax_id'],
        'accounts' => ['iban', 'account_number'],
        'invoices' => ['snapshot'],
        'recovery_codes' => ['code'],
    ];

    /**
     * Columns computed with the installation's key from an encrypted column: the column, from which, by table.
     *
     * @var array<string, array<string, string>>
     */
    public const array KEYED = ['profiles' => ['tax_id_hash' => 'tax_id']];

    /**
     * Columns, which name a row, in turn: the first one a table has labels its rows.
     *
     * @var list<string>
     */
    public const array LABELS = ['number', 'full_name', 'name', 'title', 'email', 'description', 'currency', 'event'];
}
