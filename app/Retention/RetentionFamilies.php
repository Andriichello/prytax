<?php

namespace PryTax\Retention;

/**
 * Class RetentionFamilies.
 *
 * The retention family of every table, which isn't a ФОП's data (backups.md §9.2): the tables of the backup registry,
 * but its reference ones, are `accounting` (with their notifications, files and audit history), purged with their
 * profile. A test fails on a table, which is in neither, so a new table is always given a family.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Retention
 */
final class RetentionFamilies
{
    /**
     * Tables outside the registry's profile data, by their family.
     *
     * @var array<string, string>
     */
    public const array TABLES = [
        // A removed user's row stays, anonymized; the organization's purge deletes it
        'users' => 'user_pii',
        'personal_access_tokens' => 'credentials',
        'password_reset_tokens' => 'credentials',
        'sessions' => 'credentials',
        'model_has_roles' => 'access',
        'model_has_permissions' => 'access',
        'notifications' => 'communication',
        'alert_reads' => 'communication',
        'organizations' => 'organization',
        'backups' => 'backups',
        'backup_runs' => 'backups',
        'backup_downloads' => 'backups',
        'backup_policies' => 'backups',
        'backup_policy_profiles' => 'backups',
        'idempotency_keys' => 'technical',
        'failed_jobs' => 'technical',
        'job_batches' => 'technical',
        'jobs' => 'technical',
        'cache' => 'technical',
        'cache_locks' => 'technical',
        'data_retention_runs' => 'technical',
        'data_retention_jobs' => 'technical',
        // Global reference and configuration data, which snapshots and archives reference
        'tax_rules' => 'never',
        'exchange_rates' => 'never',
        'holidays' => 'never',
        'roles' => 'never',
        'permissions' => 'never',
        'role_has_permissions' => 'never',
        'migrations' => 'never',
    ];
}
