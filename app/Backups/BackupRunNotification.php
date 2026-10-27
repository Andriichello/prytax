<?php

namespace PryTax\Backups;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use PryTax\DataLifecycle\Backup\Enums\BackupStatus;
use PryTax\DataLifecycle\Backup\Enums\RunStatus;
use PryTax\DataLifecycle\Backup\Models\BackupRun;

/**
 * Class BackupRunNotification.
 *
 * The e-mail to an organization's owners about a backup run (backups.md §2.2 `notify`): how many ФОП were backed
 * up, unchanged or failed. It never holds a link to an archive (§6): the archives are downloaded in PryTax.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Backups
 */
class BackupRunNotification extends Notification
{
    /**
     * BackupRunNotification constructor.
     *
     * @param BackupRun $run
     */
    public function __construct(public readonly BackupRun $run) {}

    /**
     * Get the notification's delivery channels.
     *
     * @param object $notifiable
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param object $notifiable
     *
     * @return MailMessage
     */
    public function toMail(object $notifiable): MailMessage
    {
        $backups = $this->run->backups()->withoutGlobalScopes()->get();
        $counts = [
            'completed' => $backups->where('status', BackupStatus::Completed)->count(),
            'skipped' => $backups->where('status', BackupStatus::SkippedUnchanged)->count(),
            'failed' => $backups->where('status', BackupStatus::Failed)->count(),
        ];

        $subject = match ($this->run->status) {
            RunStatus::Failed => __('The backup of :place failed', ['place' => $notifiable->organization->name ?? config('app.name')]),
            RunStatus::Partial => __('The backup of :place is incomplete', ['place' => $notifiable->organization->name ?? config('app.name')]),
            default => __('The backup of :place is done', ['place' => $notifiable->organization->name ?? config('app.name')]),
        };

        return (new MailMessage)
            ->subject($subject)
            ->line(__('Backed up: :completed ФОП, unchanged: :skipped, failed: :failed.', $counts))
            ->line($counts['failed'] > 0
                ? __('The next run will try again. Open PryTax to see the details and download the backups.')
                : __('Open PryTax to download the backups.'));
    }
}
