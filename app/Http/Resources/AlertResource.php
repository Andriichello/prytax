<?php

namespace PryTax\Http\Resources;

use Core\Http\Resources\BaseResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use PryTax\Models\Alert;

/**
 * Class AlertResource.
 *
 * A notification of a profile in the language of the request (the title, the body, the action's label and the
 * payload's `context`), with when the signed-in user read it (`read_at`, null while unread), which the query of the
 * list selects (AlertQuery::withReadAtOf()).
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
        $texts = $this->resource->textsIn(App::getLocale());
        unset($data['texts']);

        return [
            ...$data,
            'title' => $texts['title'],
            'body' => $texts['body'],
            'action_label' => $texts['action_label'],
            'payload' => [...$data['payload'] ?? [], 'context' => $texts['context']],
            'read_at' => $readAt?->toIso8601String(),
            'is_read' => $readAt !== null,
        ];
    }
}
