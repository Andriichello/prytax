<?php

namespace PryTax\Http\Resources;

use Core\Http\Resources\BaseCollection;

/**
 * Class AccountTransferCollection.
 *
 * A list of AccountTransferResource.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Resources
 */
class AccountTransferCollection extends BaseCollection
{
    /**
     * The resource that this resource collects.
     *
     * @var string
     */
    public $collects = AccountTransferResource::class;
}
