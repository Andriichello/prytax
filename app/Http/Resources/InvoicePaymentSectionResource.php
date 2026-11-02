<?php

namespace PryTax\Http\Resources;

use Core\Http\Resources\BaseResource;
use PryTax\Models\InvoicePaymentSection;

/**
 * Class InvoicePaymentSectionResource.
 *
 * A «Payment details (…)» section of an invoice, as the API returns it: the set it prints, its place, the text in its
 * parentheses and whether it prints them.
 *
 * @property InvoicePaymentSection $resource
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Resources
 */
class InvoicePaymentSectionResource extends BaseResource
{
    /**
     * Relations, which may be included, mapped to the resources they are output with.
     *
     * @var array
     */
    protected array $allowedIncludes = [
        'requisiteSet' => AccountRequisiteSetResource::class,
    ];

    /**
     * Attributes, which may be appended.
     *
     * @var array
     */
    protected array $allowedAppends = [];
}
