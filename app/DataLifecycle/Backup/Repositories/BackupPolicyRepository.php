<?php

namespace PryTax\DataLifecycle\Backup\Repositories;

use Core\Models\BaseModel;
use Core\Repositories\CrudRepository;
use PryTax\DataLifecycle\Backup\Http\Resources\BackupPolicyResource;
use PryTax\DataLifecycle\Backup\Models\BackupPolicy;

/**
 * Class BackupPolicyRepository.
 *
 * Repository of the backup policy of the organization.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Backup\Repositories
 */
class BackupPolicyRepository extends CrudRepository
{
    /**
     * Model of the repository.
     *
     * @var BaseModel|string
     */
    protected BaseModel|string $model = BackupPolicy::class;

    /**
     * Resource, against which includes are checked.
     *
     * @var string
     */
    protected string $resourceClass = BackupPolicyResource::class;

    /**
     * Sorts of a list, which asks for none.
     *
     * @var array<string, string>
     */
    protected array $defaultSorts = ['id' => 'asc'];
}
