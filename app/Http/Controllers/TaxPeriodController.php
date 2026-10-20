<?php

namespace PryTax\Http\Controllers;

use Core\Http\Requests\Crud\IndexRequest;
use Core\Http\Requests\Crud\ShowRequest;
use PryTax\Http\Requests\Taxes\UpdateTaxPeriodRequest;
use PryTax\Http\Resources\TaxPeriodCollection;
use PryTax\Http\Resources\TaxPeriodResource;
use PryTax\Policies\TaxPeriodPolicy;
use PryTax\Repositories\TaxPeriodRepository;

/**
 * Class TaxPeriodController.
 *
 * Tax periods (quarters) of a profile, which DeadlineScheduler creates: their declaration status and notes change.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Controllers
 */
class TaxPeriodController extends TenantController
{
    /**
     * Resource, which wraps a model.
     *
     * @var string
     */
    protected string $resourceClass = TaxPeriodResource::class;

    /**
     * Collection, which wraps a list of models.
     *
     * @var string
     */
    protected string $collectionClass = TaxPeriodCollection::class;

    /**
     * Operations, which the controller handles, mapped to the classes of their requests.
     *
     * @var array
     */
    protected array $operations = [
        'index' => IndexRequest::class,
        'show' => ShowRequest::class,
        'update' => UpdateTaxPeriodRequest::class,
    ];

    /**
     * TaxPeriodController constructor.
     *
     * @param TaxPeriodPolicy $policy
     * @param TaxPeriodRepository $repository
     */
    public function __construct(TaxPeriodPolicy $policy, TaxPeriodRepository $repository)
    {
        parent::__construct($policy, $repository);
    }
}
