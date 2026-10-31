<?php

namespace PryTax\Providers;

use Core\Models\AuditLog;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use PryTax\Backups\AccountingArchiveChecks;
use PryTax\Backups\BackupRunNotifier;
use PryTax\Backups\NbuReferenceResolver;
use PryTax\Backups\ProfileAuditTagger;
use PryTax\Backups\ProfileTenantRoot;
use PryTax\Backups\PryTaxTableRegistry;
use PryTax\DataLifecycle\Backup\Events\ArchiveImported;
use PryTax\DataLifecycle\Backup\Events\BackupRunFinished;
use PryTax\DataLifecycle\Contracts\ArchiveChecks;
use PryTax\DataLifecycle\Contracts\ReferenceResolver;
use PryTax\DataLifecycle\Contracts\RetentionFamilyHandler;
use PryTax\DataLifecycle\Contracts\TableRegistry;
use PryTax\DataLifecycle\Contracts\TenantRoot;
use PryTax\DataLifecycle\Retention\Events\RetentionRunCancelled;
use PryTax\Models\Profile;
use PryTax\Retention\AccountingFamilyHandler;
use PryTax\Retention\DocumentsFamilyHandler;
use PryTax\Retention\OrganizationPurgeHandler;
use PryTax\Retention\ProfileFinalizeHandler;
use PryTax\Retention\RetentionTriggers;
use PryTax\Retention\UserRemovalHandler;
use PryTax\Services\Alerts\AlertSync;

/**
 * Class DataLifecycleBindings.
 *
 * Binds the contracts of the backups and retention module to PryTax's definitions (backups.md §10.4): the module
 * depends on them, never on PryTax's classes. It also tags audit entries of profile data with their profile, and plans
 * the deletions of PryTax's subjects.
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
        ReferenceResolver::class => NbuReferenceResolver::class,
        ProfileAuditTagger::class => ProfileAuditTagger::class,
    ];

    /**
     * Register PryTax's retention handlers.
     *
     * @return void
     */
    public function register(): void
    {
        $this->app->tag([
            AccountingFamilyHandler::class,
            DocumentsFamilyHandler::class,
            ProfileFinalizeHandler::class,
            UserRemovalHandler::class,
            OrganizationPurgeHandler::class,
        ], RetentionFamilyHandler::TAG);
    }

    /**
     * Bootstrap the tagging of audit entries (in `creating`, since entries can't be changed afterwards), the e-mails
     * of runs, the notifications of an imported profile, and the deletions of profiles, users and organizations.
     *
     * @return void
     */
    public function boot(): void
    {
        $this->app->make(RetentionTriggers::class)->listen();
        Event::listen(RetentionRunCancelled::class, [RetentionTriggers::class, 'runCancelled']);

        AuditLog::creating(fn (AuditLog $entry) => $this->app->make(ProfileAuditTagger::class)->tag($entry));

        Event::listen(BackupRunFinished::class, [BackupRunNotifier::class, 'handle']);

        // Notifications are derived, so an archive doesn't restore them (backups.md §5.1)
        Event::listen(ArchiveImported::class, function (ArchiveImported $event) {
            $profile = Profile::query()->withoutGlobalScope(Profile::ORGANIZATION_SCOPE)->findOrFail($event->tenantId);

            $this->app->make(AlertSync::class)->sync($profile);
        });
    }
}
