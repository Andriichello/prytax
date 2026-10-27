<?php

namespace PryTax\Providers;

use Core\Models\AuditLog;
use Illuminate\Support\ServiceProvider;
use PryTax\Backups\AccountingArchiveChecks;
use PryTax\Backups\ProfileAuditTagger;
use PryTax\Backups\ProfileTenantRoot;
use PryTax\Backups\PryTaxTableRegistry;
use PryTax\DataLifecycle\Contracts\ArchiveChecks;
use PryTax\DataLifecycle\Contracts\TableRegistry;
use PryTax\DataLifecycle\Contracts\TenantRoot;

/**
 * Class DataLifecycleBindings.
 *
 * Binds the contracts of the backups and retention module to PryTax's definitions (backups.md §10.4): the module
 * depends on them, never on PryTax's classes. It also tags audit entries of profile data with their profile.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Providers
 */
class DataLifecycleBindings extends ServiceProvider
{
    /**
     * The container bindings that should be registered.
     *
     * @var array<class-string, class-string>
     */
    public array $singletons = [
        TableRegistry::class => PryTaxTableRegistry::class,
        TenantRoot::class => ProfileTenantRoot::class,
        ArchiveChecks::class => AccountingArchiveChecks::class,
        ProfileAuditTagger::class => ProfileAuditTagger::class,
    ];

    /**
     * Bootstrap the tagging of audit entries: in `creating`, since entries can't be changed afterwards.
     *
     * @return void
     */
    public function boot(): void
    {
        AuditLog::creating(fn (AuditLog $entry) => $this->app->make(ProfileAuditTagger::class)->tag($entry));
    }
}
