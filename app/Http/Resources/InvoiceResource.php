<?php

namespace PryTax\Http\Resources;

use Core\Http\Resources\BaseResource;
use Core\Http\Resources\FileCollection;
use Core\Http\Resources\FileResource;
use PryTax\Models\Invoice;

/**
 * Class InvoiceResource.
 *
 * An invoice, as the API returns it: its attributes, amounts as strings and dates as `YYYY-MM-DD`, with the includes and
 * appends, which it allows.
 *
 * @property Invoice $resource
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Resources
 */
class InvoiceResource extends BaseResource
{
    /**
     * Relations, which may be included, mapped to the resources they are output with.
     *
     * @var array
     */
    protected array $allowedIncludes = [
        'client' => ClientResource::class,
        'contract' => ContractResource::class,
        'receivingAccount' => AccountResource::class,
        'items' => InvoiceItemCollection::class,
        'payments' => PaymentCollection::class,
        'primaryFile' => FileResource::class,
        'files' => FileCollection::class,
    ];

    /**
     * Attributes, which may be appended.
     *
     * @var array
     */
    protected array $allowedAppends = [
        'number_preview',
        'can_unfreeze',
        'snapshot',
        'effective_settings',
        'payment_purpose_preview',
        'warnings',
    ];
}
