<?php

namespace PryTax\Repositories;

use Core\Models\BaseModel;
use PryTax\Http\Resources\TaxPaymentRequisiteResource;
use PryTax\Models\TaxPaymentRequisite;

/**
 * Class TaxPaymentRequisiteRepository.
 *
 * Repository of the budget requisites of taxes.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Repositories
 */
class TaxPaymentRequisiteRepository extends TenantRepository
{
    /**
     * Model of the repository.
     *
     * @var BaseModel|string
     */
    protected BaseModel|string $model = TaxPaymentRequisite::class;

    /**
     * Resource, against which includes are checked.
     *
     * @var string
     */
    protected string $resourceClass = TaxPaymentRequisiteResource::class;

    /**
     * Sorts of a list, which asks for none.
     *
     * @var array<string, string>
     */
    protected array $defaultSorts = ['tax_type' => 'asc', 'effective_from' => 'desc'];
}
