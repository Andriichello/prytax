<?php

namespace PryTax\Http\Controllers;

use Core\Http\Requests\Crud\DestroyRequest;
use Core\Http\Requests\Crud\IndexRequest;
use Core\Http\Requests\Crud\ShowRequest;
use PryTax\Http\Requests\Payments\StoreRefundRequest;
use PryTax\Http\Resources\RefundCollection;
use PryTax\Http\Resources\RefundResource;
use PryTax\Policies\RefundPolicy;
use PryTax\Repositories\RefundRepository;

/**
 * Class RefundController.
 *
 * Refunds of a receipt: `profiles/{profile}/payments/{payment}/refunds`. A refund is recorded or deleted, never edited.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Controllers
 */
class RefundController extends TenantController
{
    /**
     * Resource, which wraps a model.
     *
     * @var string
     */
    protected string $resourceClass = RefundResource::class;

    /**
     * Collection, which wraps a list of models.
     *
     * @var string
     */
    protected string $collectionClass = RefundCollection::class;

    /**
     * Operations, which the controller handles, mapped to the classes of their requests.
     *
     * @var array
     */
    protected array $operations = [
        'index' => IndexRequest::class,
        'show' => ShowRequest::class,
        'store' => StoreRefundRequest::class,
        'destroy' => DestroyRequest::class,
    ];

    /**
     * Route parameters of the models' parents, from the outermost to the innermost.
     *
     * @var array<int, string>
     */
    protected array $parents = ['profile', 'payment'];

    /**
     * RefundController constructor.
     *
     * @param RefundPolicy $policy
     * @param RefundRepository $repository
     */
    public function __construct(RefundPolicy $policy, RefundRepository $repository)
    {
        parent::__construct($policy, $repository);
    }
}
