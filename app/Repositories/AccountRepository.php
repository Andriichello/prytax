<?php

namespace PryTax\Repositories;

use Core\Models\BaseModel;
use PryTax\Http\Resources\AccountResource;
use PryTax\Models\Account;

/**
 * Class AccountRepository.
 *
 * Repository of the accounts of a profile, in their order on the screen.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Repositories
 */
class AccountRepository extends TenantRepository
{
    /**
     * Model of the repository.
     *
     * @var BaseModel|string
     */
    protected BaseModel|string $model = Account::class;

    /**
     * Whether a write of the repository's records can change the profile's notifications, so they are synced after.
     *
     * @var bool
     */
    protected bool $syncsAlerts = true;

    /**
     * Resource, against which includes are checked.
     *
     * @var string
     */
    protected string $resourceClass = AccountResource::class;

    /**
     * Sorts of a list, which asks for none.
     *
     * @var array<string, string>
     */
    protected array $defaultSorts = ['sort_order' => 'asc', 'id' => 'asc'];
}
