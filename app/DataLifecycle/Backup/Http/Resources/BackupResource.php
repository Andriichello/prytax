<?php

namespace PryTax\DataLifecycle\Backup\Http\Resources;

use Core\Http\Resources\BaseResource;
use Illuminate\Http\Request;
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
        ];
    }
}
