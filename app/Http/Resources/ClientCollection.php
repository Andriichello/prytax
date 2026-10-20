<?php

namespace PryTax\Http\Resources;

use Core\Http\Resources\BaseCollection;

/**
 * Class ClientCollection.
 *
 * A list of ClientResource.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Resources
 */
class ClientCollection extends BaseCollection
{
    /**
     * The resource that this resource collects.
     *
     * @var string
     */
    public $collects = ClientResource::class;
}
