<?php

namespace PryTax\DataLifecycle\Backup\Repositories;

use Core\Models\BaseModel;
use Core\Repositories\CrudRepository;
use PryTax\DataLifecycle\Backup\Http\Resources\BackupRunResource;
use PryTax\DataLifecycle\Backup\Models\BackupRun;

/**
 * Class BackupRunRepository.
 *
 * Repository of the runs of the organization, newest first.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Backup\Repositories
 */
class BackupRunRepository extends CrudRepository
{
    /**
     * Model of the repository.
     *
     * @var BaseModel|string
     */
    protected BaseModel|string $model = BackupRun::class;

    /**
     * Resource, against which includes are checked.
     *
     * @var string
     */
    protected string $resourceClass = BackupRunResource::class;

    /**
     * Sorts of a list, which asks for none.
     *
     * @var array<string, string>
     */
    protected array $defaultSorts = ['created_at' => 'desc', 'id' => 'desc'];

    /**
     * List of filters allowed on request: the kind, so the screen leaves the period exports out.
     *
     * @var array<int|string, string>
     */
    protected array $allowedFilters = [
        'kind',
    ];
}
