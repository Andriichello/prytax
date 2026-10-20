<?php

namespace PryTax\Http\Controllers;

use Core\Http\Requests\Crud\DestroyRequest;
use Core\Http\Requests\Crud\IndexRequest;
use Core\Http\Requests\Crud\ShowRequest;
use PryTax\Http\Requests\Taxes\StoreTaxPaymentRequisiteRequest;
use PryTax\Http\Requests\Taxes\UpdateTaxPaymentRequisiteRequest;
use PryTax\Http\Resources\TaxPaymentRequisiteCollection;
use PryTax\Http\Resources\TaxPaymentRequisiteResource;
use PryTax\Policies\TaxPaymentRequisitePolicy;
use PryTax\Repositories\TaxPaymentRequisiteRepository;

/**
 * Class TaxPaymentRequisiteController.
 *
 * Budget requisites of the taxes of a profile.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Controllers
 */
class TaxPaymentRequisiteController extends TenantController
{
    /**
     * Resource, which wraps a model.
     *
     * @var string
     */
    protected string $resourceClass = TaxPaymentRequisiteResource::class;

    /**
     * Collection, which wraps a list of models.
     *
     * @var string
     */
    protected string $collectionClass = TaxPaymentRequisiteCollection::class;

    /**
     * Operations, which the controller handles, mapped to the classes of their requests.
     *
     * @var array
     */
    protected array $operations = [
        'index' => IndexRequest::class,
        'show' => ShowRequest::class,
        'store' => StoreTaxPaymentRequisiteRequest::class,
        'update' => UpdateTaxPaymentRequisiteRequest::class,
        'destroy' => DestroyRequest::class,
    ];

    /**
     * TaxPaymentRequisiteController constructor.
     *
     * @param TaxPaymentRequisitePolicy $policy
     * @param TaxPaymentRequisiteRepository $repository
     */
    public function __construct(TaxPaymentRequisitePolicy $policy, TaxPaymentRequisiteRepository $repository)
    {
        parent::__construct($policy, $repository);
    }
}
