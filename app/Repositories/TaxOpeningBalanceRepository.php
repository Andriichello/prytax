<?php

namespace PryTax\Repositories;

use Core\Models\BaseModel;
use PryTax\Http\Resources\TaxOpeningBalanceResource;
use PryTax\Models\TaxOpeningBalance;

/**
 * Class TaxOpeningBalanceRepository.
 *
 * Repository of the opening balances of taxes.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Repositories
 */
class TaxOpeningBalanceRepository extends TenantRepository
{
    /**
     * Model of the repository.
     *
     * @var BaseModel|string
     */
    protected BaseModel|string $model = TaxOpeningBalance::class;

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
    protected string $resourceClass = TaxOpeningBalanceResource::class;

    /**
     * Sorts of a list, which asks for none.
     *
     * @var array<string, string>
     */
    protected array $defaultSorts = ['as_of' => 'desc', 'id' => 'desc'];
}
