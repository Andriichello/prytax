<?php

namespace PryTax\Http\Resources;

use Core\Http\Resources\BaseResource;
use PryTax\Models\ExchangeRate;

/**
 * Class ExchangeRateResource.
 *
 * An exchange rate, as the API returns it: its attributes, amounts as strings and dates as `YYYY-MM-DD`, with the includes and
 * appends, which it allows.
 *
 * @property ExchangeRate $resource
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Resources
 */
class ExchangeRateResource extends BaseResource
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
    protected array $allowedAppends = [];
}
