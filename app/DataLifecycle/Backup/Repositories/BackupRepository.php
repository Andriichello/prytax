<?php

namespace PryTax\DataLifecycle\Backup\Repositories;

use Core\Models\BaseModel;
use Core\Repositories\CrudRepository;
use PryTax\DataLifecycle\Backup\Http\Resources\BackupResource;
use PryTax\DataLifecycle\Backup\Models\Backup;

/**
 * Class BackupRepository.
 *
 * Repository of the archives of the organization's profiles, newest first.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Backup\Repositories
 */
class BackupRepository extends CrudRepository
{
    /**
     * Model of the repository.
     *
     * @var BaseModel|string
     */
    protected BaseModel|string $model = Backup::class;

    /**
     * Resource, against which includes are checked.
     *
     * @var string
     */
    protected string $resourceClass = BackupResource::class;

    /**
     * Sorts of a list, which asks for none.
     *
     * @var array<string, string>
     */
    protected array $defaultSorts = ['created_at' => 'desc', 'id' => 'desc'];
}
