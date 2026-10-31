<?php

namespace PryTax\Http\Resources;

use Core\Http\Resources\BaseCollection;

/**
 * Class IntegrationCollection.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Resources
 */
class IntegrationCollection extends BaseCollection
{
    /**
     * The resource that this resource collects.
     *
     * @var string
     */
    public $collects = IntegrationResource::class;
}
