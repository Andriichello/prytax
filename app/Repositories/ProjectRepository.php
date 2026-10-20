<?php

namespace PryTax\Repositories;

use Core\Models\BaseModel;
use PryTax\Http\Resources\ProjectResource;
use PryTax\Models\Project;

/**
 * Class ProjectRepository.
 *
 * Repository of the projects of clients.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Repositories
 */
class ProjectRepository extends TenantRepository
{
    /**
     * Model of the repository.
     *
     * @var BaseModel|string
     */
    protected BaseModel|string $model = Project::class;

    /**
     * Resource, against which includes are checked.
     *
     * @var string
     */
    protected string $resourceClass = ProjectResource::class;

    /**
     * Sorts of a list, which asks for none.
     *
     * @var array<string, string>
     */
    protected array $defaultSorts = ['code' => 'asc', 'id' => 'asc'];
}
