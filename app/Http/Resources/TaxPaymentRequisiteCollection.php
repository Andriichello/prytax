<?php

namespace PryTax\Http\Resources;

use Core\Http\Resources\BaseCollection;

/**
 * Class TaxPaymentRequisiteCollection.
 *
 * A list of TaxPaymentRequisiteResource.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Resources
 */
class TaxPaymentRequisiteCollection extends BaseCollection
{
    /**
     * The resource that this resource collects.
     *
     * @var string
     */
    public $collects = TaxPaymentRequisiteResource::class;
}
