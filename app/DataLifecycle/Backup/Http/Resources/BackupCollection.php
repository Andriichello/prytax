<?php

namespace PryTax\DataLifecycle\Backup\Http\Resources;

use Core\Http\Resources\BaseCollection;

/**
 * Class BackupCollection.
 *
 * A list of BackupResource.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Backup\Http\Resources
 */
class BackupCollection extends BaseCollection
{
    /**
     * The resource that this resource collects.
     *
     * @var string
     */
    public $collects = BackupResource::class;
}
