<?php

namespace PryTax\Providers;

use Core\Models\AuditLog;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use PryTax\Backups\AccountingArchiveChecks;
use PryTax\Backups\ProfileAuditTagger;
use PryTax\Backups\ProfileTenantRoot;
use PryTax\Backups\PryTaxTableRegistry;
use PryTax\DataLifecycle\Backup\Events\ArchiveImported;
use PryTax\DataLifecycle\Contracts\ArchiveChecks;
use PryTax\DataLifecycle\Contracts\TableRegistry;
use PryTax\DataLifecycle\Contracts\TenantRoot;
use PryTax\Models\Profile;
use PryTax\Services\Alerts\AlertSync;

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
     * Bootstrap the tagging of audit entries (in `creating`, since entries can't be changed afterwards), and the
     * notifications of an imported profile.
     *
     * @return void
     */
    public function boot(): void
    {
        AuditLog::creating(fn (AuditLog $entry) => $this->app->make(ProfileAuditTagger::class)->tag($entry));

        // Notifications are derived, so an archive doesn't restore them (backups.md §5.1)
        Event::listen(ArchiveImported::class, function (ArchiveImported $event) {
            $profile = Profile::query()->withoutGlobalScope(Profile::ORGANIZATION_SCOPE)->findOrFail($event->tenantId);

            $this->app->make(AlertSync::class)->sync($profile);
        });
    }
}
