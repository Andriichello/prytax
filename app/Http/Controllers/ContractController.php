<?php

namespace PryTax\Http\Controllers;

use Core\Http\Requests\Crud\DestroyRequest;
use Core\Http\Requests\Crud\IndexRequest;
use Core\Http\Requests\Crud\ShowRequest;
use PryTax\Http\Requests\Clients\StoreContractRequest;
use PryTax\Http\Requests\Clients\UpdateContractRequest;
use PryTax\Http\Resources\ContractCollection;
use PryTax\Http\Resources\ContractResource;
use PryTax\Policies\ContractPolicy;
use PryTax\Repositories\ContractRepository;

/**
 * Class ContractController.
 *
 * Contracts of a client, with their hourly rates: `profiles/{profile}/clients/{client}/contracts`.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Controllers
 */
class ContractController extends TenantController
{
    /**
     * Resource, which wraps a model.
     *
     * @var string
     */
    protected string $resourceClass = ContractResource::class;

    /**
     * Collection, which wraps a list of models.
     *
     * @var string
     */
    protected string $collectionClass = ContractCollection::class;

    /**
     * Operations, which the controller handles, mapped to the classes of their requests.
     *
     * @var array
     */
    protected array $operations = [
        'index' => IndexRequest::class,
        'show' => ShowRequest::class,
        'store' => StoreContractRequest::class,
        'update' => UpdateContractRequest::class,
        'destroy' => DestroyRequest::class,
    ];

    /**
     * Route parameters of the models' parents, from the outermost to the innermost.
     *
     * @var array<int, string>
     */
    protected array $parents = ['profile', 'client'];

    /**
     * ContractController constructor.
     *
     * @param ContractPolicy $policy
     * @param ContractRepository $repository
     */
    public function __construct(ContractPolicy $policy, ContractRepository $repository)
    {
        parent::__construct($policy, $repository);
    }
}
