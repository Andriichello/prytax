<?php

namespace PryTax\Repositories;

use Core\Models\BaseModel;
use PryTax\Http\Resources\AccountOpeningBalanceResource;
use PryTax\Models\AccountOpeningBalance;

/**
 * Class AccountOpeningBalanceRepository.
 *
 * Repository of the opening balances of accounts.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Repositories
 */
class AccountOpeningBalanceRepository extends TenantRepository
{
    /**
     * Model of the repository.
     *
     * @var BaseModel|string
     */
    protected BaseModel|string $model = AccountOpeningBalance::class;

    /**
     * Resource, against which includes are checked.
     *
     * @var string
     */
    protected string $resourceClass = AccountOpeningBalanceResource::class;

    /**
     * Sorts of a list, which asks for none.
     *
     * @var array<string, string>
     */
    protected array $defaultSorts = ['as_of' => 'desc', 'id' => 'desc'];
}
