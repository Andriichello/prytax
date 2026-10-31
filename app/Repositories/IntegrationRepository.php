<?php

namespace PryTax\Repositories;

use Core\Models\BaseModel;
use Core\Repositories\CrudRepository;
use PryTax\Http\Resources\IntegrationResource;
use PryTax\Models\Integration;

/**
 * Class IntegrationRepository.
 *
 * The organization's integrations, which `IntegrationController` serves through its own operations.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Repositories
 */
class IntegrationRepository extends CrudRepository
{
    /**
     * Model, which the repository works with.
     *
     * @var BaseModel|string
     */
    protected BaseModel|string $model = Integration::class;

    /**
     * Resource class for the model.
     *
     * @var string
     */
    protected string $resourceClass = IntegrationResource::class;

    /**
     * Default sorts, which apply when none are requested.
     *
     * @var array<string, string>
     */
    protected array $defaultSorts = ['id' => 'asc'];
}
