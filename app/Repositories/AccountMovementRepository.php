<?php

namespace PryTax\Repositories;

use Core\Models\BaseModel;
use PryTax\Http\Resources\AccountMovementResource;
use PryTax\Models\AccountMovement;

/**
 * Class AccountMovementRepository.
 *
 * Repository of the non-taxable movements of accounts.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Repositories
 */
class AccountMovementRepository extends TenantRepository
{
    /**
     * Model of the repository.
     *
     * @var BaseModel|string
     */
    protected BaseModel|string $model = AccountMovement::class;

    /**
     * Resource, against which includes are checked.
     *
     * @var string
     */
    protected string $resourceClass = AccountMovementResource::class;

    /**
     * Sorts of a list, which asks for none.
     *
     * @var array<string, string>
     */
    protected array $defaultSorts = ['booked_at' => 'desc', 'id' => 'desc'];
}
