<?php

namespace PryTax\Http\Resources;

use Core\Http\Resources\BaseResource;
use Illuminate\Http\Request;
use PryTax\Models\Alert;

/**
 * Class AlertResource.
 *
 * A notification of a profile, with when the signed-in user read it (`read_at`, null while unread), which the
 * query of the list selects (AlertQuery::withReadAtOf()).
 *
 * @property Alert $resource
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Resources
 */
class AlertResource extends BaseResource
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
        $data = parent::toArray($request);
        $readAt = $this->resource->getAttribute('read_at');

        return [
            ...$data,
            'read_at' => $readAt?->toIso8601String(),
            'is_read' => $readAt !== null,
        ];
    }
}
