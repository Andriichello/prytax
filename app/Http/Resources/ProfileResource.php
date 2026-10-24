<?php

namespace PryTax\Http\Resources;

use Core\Http\Resources\BaseResource;
use Illuminate\Http\Request;
use PryTax\Models\Profile;
use PryTax\Policies\ProfilePolicy;

/**
 * Class ProfileResource.
 *
 * A profile (ФОП), as the API returns it: its attributes, amounts as strings and dates as `YYYY-MM-DD`, with the includes and
 * appends, which it allows. The full tax id is shown only to users, who can configure the profile; others see
 * `tax_id_masked`.
 *
 * @property Profile $resource
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Resources
 */
class ProfileResource extends BaseResource
{
    /**
     * Relations, which may be included, mapped to the resources they are output with.
     *
     * @var array
     */
    protected array $allowedIncludes = [];

    /**
     * Attributes, which may be appended.
     *
     * @var array
     */
    protected array $allowedAppends = [
        'unread_alerts_count',
        'needs_correction',
        'effective_invoice_settings',
    ];

    /**
     * Transform the resource into an array, without the full tax id for users, who can't configure the profile.
     *
     * @param Request $request
     *
     * @return array
     */
    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);

        if (!app(ProfilePolicy::class)->canConfigure($request->user(), $this->resource)) {
            unset($data['tax_id']);
        }

        return $data;
    }
}
