<?php

namespace PryTax\DataLifecycle\Backup\Http\Resources;

use Core\Http\Resources\BaseResource;
use Illuminate\Http\Request;
use PryTax\DataLifecycle\Backup\Enums\BackupStatus;
use PryTax\DataLifecycle\Backup\Internal\RetentionEstimate;
use PryTax\DataLifecycle\Backup\Models\Backup;

/**
 * Class BackupResource.
 *
 * A profile's backup, without where its archive is stored (downloads go through signed URLs), with the documents
 * its archive holds.
 *
 * @property Backup $resource
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Backup\Http\Resources
 */
class BackupResource extends BaseResource
{
    /**
     * Transform the resource into an array.
     *
     * @param Request $request
     *
     * @return array
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'documents' => $this->resource->manifest['documents']['count'] ?? null,
            'has_archive' => $this->resource->hasArchive(),
            ...$this->keeping(),
            'previous' => $this->previous(),
        ];
    }

    /**
     * Get how long a completed backup stays under the current policy: `keep_until` (a date, or null until deleted) and
     * `keep_reason` (`newest`, `manual`, `day`, `week`, `month`, `expires`, `next_prune`).
     *
     * @return array{keep_until: string|null, keep_reason: string|null}
     */
    protected function keeping(): array
    {
        if ($this->resource->status !== BackupStatus::Completed) {
            return ['keep_until' => null, 'keep_reason' => null];
        }

        $estimate = app(RetentionEstimate::class)->of($this->resource);

        return ['keep_until' => $estimate['until'], 'keep_reason' => $estimate['reason']];
    }

    /**
     * Get the archive, which a backup skipped as unchanged stands for: the profile's newest completed backup before it,
     * whose archive its download gives (backups.md §4.5, §4.8), with its size, date and documents.
     *
     * @return array{id: string, bytes: int|null, created_at: string|null, documents: int|null}|null
     */
    protected function previous(): ?array
    {
        if ($this->resource->status !== BackupStatus::SkippedUnchanged) {
            return null;
        }

        $previous = Backup::query()
            ->where('profile_id', $this->resource->profile_id)
            ->where('status', BackupStatus::Completed)
            ->where('created_at', '<=', $this->resource->created_at)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        return $previous === null ? null : [
            'id' => $previous->id,
            'bytes' => $previous->bytes,
            'created_at' => $previous->created_at?->toIso8601String(),
            'documents' => $previous->manifest['documents']['count'] ?? null,
        ];
    }
}
