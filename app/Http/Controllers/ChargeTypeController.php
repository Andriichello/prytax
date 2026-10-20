<?php

namespace PryTax\Http\Controllers;

use Core\Http\Requests\Crud\DestroyRequest;
use Core\Http\Requests\Crud\IndexRequest;
use Core\Http\Requests\Crud\ShowRequest;
use PryTax\Http\Requests\Clients\StoreChargeTypeRequest;
use PryTax\Http\Requests\Clients\UpdateChargeTypeRequest;
use PryTax\Http\Resources\ChargeTypeCollection;
use PryTax\Http\Resources\ChargeTypeResource;
use PryTax\Policies\ChargeTypePolicy;
use PryTax\Repositories\ChargeTypeRepository;

/**
 * Class ChargeTypeController.
 *
 * Charge types of a client: `profiles/{profile}/clients/{client}/charge-types`.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Controllers
 */
class ChargeTypeController extends TenantController
{
    /**
     * Resource, which wraps a model.
     *
     * @var string
     */
    protected string $resourceClass = ChargeTypeResource::class;

    /**
     * Collection, which wraps a list of models.
     *
     * @var string
     */
    protected string $collectionClass = ChargeTypeCollection::class;

    /**
     * Operations, which the controller handles, mapped to the classes of their requests.
     *
     * @var array
     */
    protected array $operations = [
        'index' => IndexRequest::class,
        'show' => ShowRequest::class,
        'store' => StoreChargeTypeRequest::class,
        'update' => UpdateChargeTypeRequest::class,
        'destroy' => DestroyRequest::class,
    ];

    /**
     * Route parameters of the models' parents, from the outermost to the innermost.
     *
     * @var array<int, string>
     */
    protected array $parents = ['profile', 'client'];

    /**
     * ChargeTypeController constructor.
     *
     * @param ChargeTypePolicy $policy
     * @param ChargeTypeRepository $repository
     */
    public function __construct(ChargeTypePolicy $policy, ChargeTypeRepository $repository)
    {
        parent::__construct($policy, $repository);
    }
}
