<?php

namespace PryTax\Http\Resources;

use Core\Http\Resources\BaseCollection;

/**
 * Class InvoicePaymentSectionCollection.
 *
 * The «Payment details (…)» sections of an invoice, as the API returns them.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Resources
 */
class InvoicePaymentSectionCollection extends BaseCollection
{
    /**
     * The resource that this resource collects.
     *
     * @var string
     */
    public $collects = InvoicePaymentSectionResource::class;
}
