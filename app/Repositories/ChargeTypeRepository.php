<?php

namespace PryTax\Repositories;

use Core\Models\BaseModel;
use PryTax\Http\Resources\ChargeTypeResource;
use PryTax\Models\ChargeType;

/**
 * Class ChargeTypeRepository.
 *
 * Repository of the charge types of clients.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Repositories
 */
class ChargeTypeRepository extends TenantRepository
{
    /**
     * Model of the repository.
     *
     * @var BaseModel|string
     */
    protected BaseModel|string $model = ChargeType::class;

    /**
     * Resource, against which includes are checked.
     *
     * @var string
     */
    protected string $resourceClass = ChargeTypeResource::class;

    /**
     * Sorts of a list, which asks for none.
     *
     * @var array<string, string>
     */
    protected array $defaultSorts = ['name' => 'asc', 'id' => 'asc'];
}
