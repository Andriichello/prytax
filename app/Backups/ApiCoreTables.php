<?php

namespace PryTax\Backups;

use Core\Models\User;
use PryTax\DataLifecycle\Registry\TableSpec;

/**
 * Class ApiCoreTables.
 *
 * The api-core (`Core\`) tables, which PryTax backs up, and the ones it leaves out (backups.md §11.1): api-core has
 * no backup code of its own. The column snapshot covers them like PryTax's tables, so an api-core upgrade, which
 * changes them, fails the tests until it is reviewed.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Backups
 */
final class ApiCoreTables
{
    /**
     * Option of a run, with which the archives hold the audit log of their profiles.
     *
     * @var string
     */
    public const string INCLUDE_AUDIT_LOG = 'include_audit_log';

    /**
     * Tables of api-core, which aren't backed up, with the reason.
     *
     * @var array<string, string>
     */
    public const array EXCLUDED = [
        'notifications' => 'per-user notifications (notifiable_*), not profile data',
        'personal_access_tokens' => 'credentials, never exported',
        'password_reset_tokens' => 'credentials, never exported',
        'sessions' => 'credentials, never exported',
        'roles' => 'global configuration',
        'permissions' => 'global configuration',
        'role_has_permissions' => 'global configuration',
        'model_has_roles' => 'organization-level access, not profile data',
        'model_has_permissions' => 'organization-level access, not profile data',
    ];

    /**
     * Tables, which reach their owner through a type/id pair: no `profile_id`, so they are selected through the
     * exported owners, and placed after every one of them.
     *
     * @return array<int, TableSpec>
     */
    public static function owned(): array
    {
        return [
            TableSpec::morph('settings', morph: 'model')->file('core/settings')
                // Ids of the profile's accounts (backups.md §4.2 "Ids inside values")
                ->rewrite('value', to: 'accounts', json: '$', when: ['key' => ['taxes.payment_account_ids', 'taxes.default_account_id']]),
            TableSpec::morph('sequences', morph: 'model')->file('core/sequences')
                // invoices:client:{id}[:year]
                ->rewrite('key', to: 'clients', pattern: '/client:(\d+)/'),
            TableSpec::morph('files', morph: 'fileable')->file('core/files')
                ->documents(disk: 'disk', path: 'path'),
            // The history of the source profile, tagged by ProfileAuditTagger: read-only, never imported
            TableSpec::profileJson('audit_logs', column: 'metadata', path: '$.profile_id')->file('core/audit_logs')
                ->optional(self::INCLUDE_AUDIT_LOG)
                ->exportOnly(),
        ];
    }

    /**
     * Global tables of api-core, which exported rows reference: last in the registry.
     *
     * @return array<int, TableSpec>
     */
    public static function references(): array
    {
        return [
            // The users, who created records or caused audit entries; matched by e-mail within the target organization
            TableSpec::reference('users', via: ['*.created_by_user_id'])
                ->viaMorph('audit_logs', morph: 'causer', alias: (new User)->getMorphClass())
                ->only('id', 'name', 'email')
                ->matchOn('email', within: 'organization', orNull: true),
        ];
    }
}
