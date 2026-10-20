<?php

namespace PryTax\Http\Controllers;

use Core\Http\Requests\Crud\DestroyRequest;
use Core\Http\Requests\Crud\IndexRequest;
use Core\Http\Requests\Crud\ShowRequest;
use PryTax\Http\Requests\Taxes\StoreTaxPaymentRequest;
use PryTax\Http\Requests\Taxes\UpdateTaxPaymentRequest;
use PryTax\Http\Resources\TaxPaymentCollection;
use PryTax\Http\Resources\TaxPaymentResource;
use PryTax\Policies\TaxPaymentPolicy;
use PryTax\Repositories\TaxPaymentRepository;

/**
 * Class TaxPaymentController.
 *
 * Payments of ЄП, ВЗ and ЄСВ of a profile.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Controllers
 */
class TaxPaymentController extends TenantController
{
    /**
     * Resource, which wraps a model.
     *
     * @var string
     */
    protected string $resourceClass = TaxPaymentResource::class;

    /**
     * Collection, which wraps a list of models.
     *
     * @var string
     */
    protected string $collectionClass = TaxPaymentCollection::class;

    /**
     * Operations, which the controller handles, mapped to the classes of their requests.
     *
     * @var array
     */
    protected array $operations = [
        'index' => IndexRequest::class,
        'show' => ShowRequest::class,
        'store' => StoreTaxPaymentRequest::class,
        'update' => UpdateTaxPaymentRequest::class,
        'destroy' => DestroyRequest::class,
    ];

    /**
     * TaxPaymentController constructor.
     *
     * @param TaxPaymentPolicy $policy
     * @param TaxPaymentRepository $repository
     */
    public function __construct(TaxPaymentPolicy $policy, TaxPaymentRepository $repository)
    {
        parent::__construct($policy, $repository);
    }
}
