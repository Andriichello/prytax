<?php

namespace PryTax\DataLifecycle\Backup\Http\Resources;

use Core\Http\Resources\BaseCollection;

/**
 * Class BackupPolicyCollection.
 *
 * A list of BackupPolicyResource.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Backup\Http\Resources
 */
class BackupPolicyCollection extends BaseCollection
{
    /**
     * The resource that this resource collects.
     *
     * @var string
     */
    public $collects = BackupPolicyResource::class;
}
