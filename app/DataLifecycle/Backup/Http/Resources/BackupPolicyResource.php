<?php

namespace PryTax\DataLifecycle\Backup\Http\Resources;

use Core\Http\Resources\BaseResource;
use Illuminate\Http\Request;
use PryTax\DataLifecycle\Backup\Models\BackupPolicy;

/**
 * Class BackupPolicyResource.
 *
 * The organization's backup policy, with its selected profiles.
 *
 * @property BackupPolicy $resource
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Backup\Http\Resources
 */
class BackupPolicyResource extends BaseResource
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
            'profile_ids' => $this->resource->profileIds(),
        ];
    }
}
