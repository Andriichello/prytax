<?php

namespace PryTax\Http\Resources;

use Core\Http\Resources\BaseResource;
use PryTax\Models\Client;

/**
 * Class ClientResource.
 *
 * A client, as the API returns it: its attributes, amounts as strings and dates as `YYYY-MM-DD`, with the includes and
 * appends, which it allows.
 *
 * @property Client $resource
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Resources
 */
class ClientResource extends BaseResource
{
    /**
     * Relations, which may be included, mapped to the resources they are output with.
     *
     * @var array
     */
    protected array $allowedIncludes = [
        'contracts' => ContractCollection::class,
        'projects' => ProjectCollection::class,
        'chargeTypes' => ChargeTypeCollection::class,
    ];

    /**
     * Attributes, which may be appended.
     *
     * @var array
     */
    protected array $allowedAppends = [
        'outstanding',
        'effective_settings',
    ];
}
