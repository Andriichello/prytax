<?php

namespace PryTax\DataLifecycle\Backup\Http\Resources;

use Core\Http\Resources\BaseResource;
use Illuminate\Http\Request;
use PryTax\DataLifecycle\Backup\Models\BackupRun;

/**
 * Class BackupRunResource.
 *
 * A run of the organization's backup, with its backups when included, and whether its bundle is ready.
 *
 * @property BackupRun $resource
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Backup\Http\Resources
 */
class BackupRunResource extends BaseResource
{
    /**
     * Relations, which may be included, with their resources.
     *
     * @var array<string, string>
     */
    protected array $allowedIncludes = [
        'backups' => BackupCollection::class,
    ];

    /**
     * Transform the resource into an array.
     *
     * @param Request $request
     *
     * @return array
     */
    public function toArray(Request $request): array
    {
        $expired = $this->resource->bundle_expires_at?->isPast() ?? false;

        return [
            ...parent::toArray($request),
            'bundle_status' => $expired ? null : $this->resource->bundle_status,
            'bundle_expires_at' => $expired ? null : $this->resource->bundle_expires_at?->toIso8601String(),
        ];
    }
}
