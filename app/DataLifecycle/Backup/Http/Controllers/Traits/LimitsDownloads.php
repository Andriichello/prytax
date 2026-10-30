<?php

namespace PryTax\DataLifecycle\Backup\Http\Controllers\Traits;

use Core\Http\Responses\ApiResponse;
use Illuminate\Http\Request;
use PryTax\DataLifecycle\Backup\Models\BackupDownload;

/**
 * Trait LimitsDownloads.
 *
 * The hourly limit of a user's download links (backups.md §4.7): of a backup, a bundle or an export alike, counted
 * by the links `backup_downloads` logged, so the requests that only ask whether an archive is ready don't count.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Backup\Http\Controllers\Traits
 */
trait LimitsDownloads
{
    /**
     * Get the refusal of another download link, when the user has had as many in the last hour as the limit allows,
     * or null.
     *
     * @param Request $request
     *
     * @return ApiResponse|null
     */
    protected function refuseDownload(Request $request): ?ApiResponse
    {
        $limit = (int) config('data-lifecycle.backups.downloads_per_hour');
        $issued = BackupDownload::query()
            ->where('user_id', $request->user()?->getKey())
            ->where('created_at', '>', now()->subHour())
            ->count();

        if ($issued < $limit) {
            return null;
        }

        return ApiResponse::make([], 429, __('You have downloaded :count archives in the last hour already.', ['count' => $limit]));
    }
}
