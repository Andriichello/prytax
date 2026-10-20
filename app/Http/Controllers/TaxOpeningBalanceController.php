<?php

namespace PryTax\Http\Controllers;

use Core\Http\Requests\Crud\DestroyRequest;
use Core\Http\Requests\Crud\IndexRequest;
use Core\Http\Requests\Crud\ShowRequest;
use PryTax\Http\Requests\Taxes\StoreTaxOpeningBalanceRequest;
use PryTax\Http\Requests\Taxes\UpdateTaxOpeningBalanceRequest;
use PryTax\Http\Resources\TaxOpeningBalanceCollection;
use PryTax\Http\Resources\TaxOpeningBalanceResource;
use PryTax\Policies\TaxOpeningBalancePolicy;
use PryTax\Repositories\TaxOpeningBalanceRepository;

/**
 * Class TaxOpeningBalanceController.
 *
 * Opening balances of the taxes of a profile.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Controllers
 */
class TaxOpeningBalanceController extends TenantController
{
    /**
     * Resource, which wraps a model.
     *
     * @var string
     */
    protected string $resourceClass = TaxOpeningBalanceResource::class;

    /**
     * Collection, which wraps a list of models.
     *
     * @var string
     */
    protected string $collectionClass = TaxOpeningBalanceCollection::class;

    /**
     * Operations, which the controller handles, mapped to the classes of their requests.
     *
     * @var array
     */
    protected array $operations = [
        'index' => IndexRequest::class,
        'show' => ShowRequest::class,
        'store' => StoreTaxOpeningBalanceRequest::class,
        'update' => UpdateTaxOpeningBalanceRequest::class,
        'destroy' => DestroyRequest::class,
    ];

    /**
     * TaxOpeningBalanceController constructor.
     *
     * @param TaxOpeningBalancePolicy $policy
     * @param TaxOpeningBalanceRepository $repository
     */
    public function __construct(TaxOpeningBalancePolicy $policy, TaxOpeningBalanceRepository $repository)
    {
        parent::__construct($policy, $repository);
    }
}
