<?php

namespace PryTax\Http\Controllers;

use Core\Http\Requests\Crud\DestroyRequest;
use Core\Http\Requests\Crud\IndexRequest;
use Core\Http\Requests\Crud\ShowRequest;
use PryTax\Http\Requests\Accounts\StoreMovementRequest;
use PryTax\Http\Requests\Accounts\UpdateMovementRequest;
use PryTax\Http\Resources\AccountMovementCollection;
use PryTax\Http\Resources\AccountMovementResource;
use PryTax\Policies\AccountMovementPolicy;
use PryTax\Repositories\AccountMovementRepository;

/**
 * Class AccountMovementController.
 *
 * Non-taxable movements of an account: `profiles/{profile}/accounts/{account}/movements`.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Controllers
 */
class AccountMovementController extends TenantController
{
    /**
     * Resource, which wraps a model.
     *
     * @var string
     */
    protected string $resourceClass = AccountMovementResource::class;

    /**
     * Collection, which wraps a list of models.
     *
     * @var string
     */
    protected string $collectionClass = AccountMovementCollection::class;

    /**
     * Operations, which the controller handles, mapped to the classes of their requests.
     *
     * @var array
     */
    protected array $operations = [
        'index' => IndexRequest::class,
        'show' => ShowRequest::class,
        'store' => StoreMovementRequest::class,
        'update' => UpdateMovementRequest::class,
        'destroy' => DestroyRequest::class,
    ];

    /**
     * Route parameters of the models' parents, from the outermost to the innermost.
     *
     * @var array<int, string>
     */
    protected array $parents = ['profile', 'account'];

    /**
     * AccountMovementController constructor.
     *
     * @param AccountMovementPolicy $policy
     * @param AccountMovementRepository $repository
     */
    public function __construct(AccountMovementPolicy $policy, AccountMovementRepository $repository)
    {
        parent::__construct($policy, $repository);
    }
}
