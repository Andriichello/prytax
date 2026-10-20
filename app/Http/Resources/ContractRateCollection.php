<?php

namespace PryTax\Http\Resources;

use Core\Http\Resources\BaseCollection;

/**
 * Class ContractRateCollection.
 *
 * A list of ContractRateResource.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Resources
 */
class ContractRateCollection extends BaseCollection
{
    /**
     * The resource that this resource collects.
     *
     * @var string
     */
    public $collects = ContractRateResource::class;
}
