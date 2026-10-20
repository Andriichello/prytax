<?php

namespace PryTax\Http\Resources;

use Core\Http\Resources\BaseResource;
use PryTax\Models\AccountTransfer;

/**
 * Class AccountTransferResource.
 *
 * A transfer between two accounts, as the API returns it: its attributes, amounts as strings and dates as `YYYY-MM-DD`, with the includes and
 * appends, which it allows.
 *
 * @property AccountTransfer $resource
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Resources
 */
class AccountTransferResource extends BaseResource
{
    /**
     * Relations, which may be included, mapped to the resources they are output with.
     *
     * @var array
     */
    protected array $allowedIncludes = [
        'fromAccount' => AccountResource::class,
        'toAccount' => AccountResource::class,
        'allocations' => TransferAllocationCollection::class,
    ];

    /**
     * Attributes, which may be appended.
     *
     * @var array
     */
    protected array $allowedAppends = [
        'fx_spread',
        'effective_rate',
    ];
}
