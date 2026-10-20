<?php

namespace PryTax\Repositories;

use Core\Models\BaseModel;
use Core\Repositories\CrudRepository;
use PryTax\Http\Resources\ExchangeRateResource;
use PryTax\Models\ExchangeRate;

/**
 * Class ExchangeRateRepository.
 *
 * Repository of the exchange rates.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Repositories
 */
class ExchangeRateRepository extends CrudRepository
{
    /**
     * Model of the repository.
     *
     * @var BaseModel|string
     */
    protected BaseModel|string $model = ExchangeRate::class;

    /**
     * Resource, against which includes are checked.
     *
     * @var string
     */
    protected string $resourceClass = ExchangeRateResource::class;

    /**
     * Sorts of a list, which asks for none.
     *
     * @var array<string, string>
     */
    protected array $defaultSorts = ['rate_date' => 'desc', 'currency' => 'asc'];
}
