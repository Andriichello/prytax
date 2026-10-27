<?php

namespace PryTax\Backups;

use Core\Models\User;
use Illuminate\Support\Facades\Notification;
use PryTax\DataLifecycle\Backup\Enums\BackupKind;
use PryTax\DataLifecycle\Backup\Enums\BackupNotify;
use PryTax\DataLifecycle\Backup\Enums\RunStatus;
use PryTax\DataLifecycle\Backup\Events\BackupRunFinished;
use PryTax\DataLifecycle\Backup\Models\BackupPolicy;
use PryTax\DataLifecycle\Backup\Models\BackupRun;

/**
 * Class BackupRunNotifier.
 *
 * Tells an organization's owners of its finished runs by its policy (backups.md §2.2): of failed and partial ones
 * (the default), of every one, or never. Period exports tell nobody: their user waits for them in the app.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Backups
 */
class BackupRunNotifier
{
    /**
     * Handle the event.
     *
     * @param BackupRunFinished $event
     *
     * @return void
     */
    public function handle(BackupRunFinished $event): void
    {
        $run = BackupRun::query()->withoutGlobalScopes()->find($event->runId);

        if ($run === null || $run->kind === BackupKind::Export) {
            return;
        }

        $failed = in_array($run->status, [RunStatus::Failed, RunStatus::Partial], true);

        $notify = match (BackupPolicy::of($run->organization_id)->notify) {
            BackupNotify::Always => true,
            BackupNotify::Failures => $failed,
            BackupNotify::Never => false,
        };

        if (!$notify) {
            return;
        }

        $owners = User::query()
            ->where('organization_id', $run->organization_id)
            ->whereNull('suspended_at')
            ->role('owner')
            ->get();

        Notification::send($owners, new BackupRunNotification($run));
    }
}
