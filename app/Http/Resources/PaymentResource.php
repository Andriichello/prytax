<?php

namespace PryTax\Http\Resources;

use Core\Http\Resources\BaseResource;
use PryTax\Models\Payment;

/**
 * Class PaymentResource.
 *
 * A receipt, as the API returns it: its attributes, amounts as strings and dates as `YYYY-MM-DD`, with the includes and
 * appends, which it allows.
 *
 * @property Payment $resource
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Resources
 */
class PaymentResource extends BaseResource
{
    /**
     * Relations, which may be included, mapped to the resources they are output with.
     *
     * @var array
     */
    protected array $allowedIncludes = [
        'invoice' => InvoiceResource::class,
        'receivedToAccount' => AccountResource::class,
        'refunds' => RefundCollection::class,
        'allocations' => TransferAllocationCollection::class,
        'taxPayments' => TaxPaymentCollection::class,
        'taxRule' => TaxRuleResource::class,
    ];

    /**
     * Attributes, which may be appended.
     *
     * @var array
     */
    protected array $allowedAppends = [
        'metrics',
    ];
}
