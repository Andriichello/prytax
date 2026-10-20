<?php

namespace PryTax\Http\Resources;

use Core\Http\Resources\BaseResource;
use PryTax\Models\Account;

/**
 * Class AccountResource.
 *
 * An account, as the API returns it: its attributes, amounts as strings and dates as `YYYY-MM-DD`, with the includes and
 * appends, which it allows.
 *
 * @property Account $resource
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Resources
 */
class AccountResource extends BaseResource
{
    /**
     * Relations, which may be included, mapped to the resources they are output with.
     *
     * @var array
     */
    protected array $allowedIncludes = [
        'openingBalances' => AccountOpeningBalanceCollection::class,
    ];

    /**
     * Attributes, which may be appended.
     *
     * @var array
     */
    protected array $allowedAppends = [
        'balance',
        'limit_progress',
        'late_holdings',
    ];
}
