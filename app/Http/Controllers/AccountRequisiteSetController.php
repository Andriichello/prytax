<?php

namespace PryTax\Http\Controllers;

use Core\Http\Requests\Crud\DestroyRequest;
use Core\Http\Requests\Crud\IndexRequest;
use Core\Http\Requests\Crud\ShowRequest;
use PryTax\Http\Requests\Accounts\StoreRequisiteSetRequest;
use PryTax\Http\Requests\Accounts\UpdateRequisiteSetRequest;
use PryTax\Http\Resources\AccountRequisiteSetCollection;
use PryTax\Http\Resources\AccountRequisiteSetResource;
use PryTax\Policies\AccountRequisiteSetPolicy;
use PryTax\Repositories\AccountRequisiteSetRepository;

/**
 * Class AccountRequisiteSetController.
 *
 * Sets of an account's requisites, which invoices print: `profiles/{profile}/accounts/{account}/requisite-sets`.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Controllers
 */
class AccountRequisiteSetController extends TenantController
{
    /**
     * Resource, which wraps a model.
     *
     * @var string
     */
    protected string $resourceClass = AccountRequisiteSetResource::class;

    /**
     * Collection, which wraps a list of models.
     *
     * @var string
     */
    protected string $collectionClass = AccountRequisiteSetCollection::class;

    /**
     * Operations, which the controller handles, mapped to the classes of their requests.
     *
     * @var array
     */
    protected array $operations = [
        'index' => IndexRequest::class,
        'show' => ShowRequest::class,
        'store' => StoreRequisiteSetRequest::class,
        'update' => UpdateRequisiteSetRequest::class,
        'destroy' => DestroyRequest::class,
    ];

    /**
     * Route parameters of the models' parents, from the outermost to the innermost.
     *
     * @var array<int, string>
     */
    protected array $parents = ['profile', 'account'];

    /**
     * AccountRequisiteSetController constructor.
     *
     * @param AccountRequisiteSetPolicy $policy
     * @param AccountRequisiteSetRepository $repository
     */
    public function __construct(AccountRequisiteSetPolicy $policy, AccountRequisiteSetRepository $repository)
    {
        parent::__construct($policy, $repository);
    }
}
