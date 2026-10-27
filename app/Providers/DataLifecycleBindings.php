<?php

namespace PryTax\Providers;

use Illuminate\Support\ServiceProvider;
use PryTax\Backups\AccountingArchiveChecks;
use PryTax\Backups\ProfileTenantRoot;
use PryTax\Backups\PryTaxTableRegistry;
use PryTax\DataLifecycle\Contracts\ArchiveChecks;
use PryTax\DataLifecycle\Contracts\TableRegistry;
use PryTax\DataLifecycle\Contracts\TenantRoot;

/**
 * Class DataLifecycleBindings.
 *
 * Binds the contracts of the backups and retention module to PryTax's definitions (backups.md §10.4): the module
 * depends on them, never on PryTax's classes.
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
    ];
}
