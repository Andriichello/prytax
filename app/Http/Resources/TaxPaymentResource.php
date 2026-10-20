<?php

namespace PryTax\Http\Resources;

use Core\Http\Resources\BaseResource;
use PryTax\Models\TaxPayment;

/**
 * Class TaxPaymentResource.
 *
 * A tax payment, as the API returns it: its attributes, amounts as strings and dates as `YYYY-MM-DD`, with the includes and
 * appends, which it allows.
 *
 * @property TaxPayment $resource
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Resources
 */
class TaxPaymentResource extends BaseResource
{
    /**
     * Relations, which may be included, mapped to the resources they are output with.
     *
     * @var array
     */
    protected array $allowedIncludes = [
        'payment' => PaymentResource::class,
        'paidFromAccount' => AccountResource::class,
    ];

    /**
     * Attributes, which may be appended.
     *
     * @var array
     */
    protected array $allowedAppends = [];
}
