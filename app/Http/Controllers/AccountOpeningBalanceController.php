<?php

namespace PryTax\Http\Controllers;

use Core\Http\Requests\Crud\DestroyRequest;
use Core\Http\Requests\Crud\IndexRequest;
use Core\Http\Requests\Crud\ShowRequest;
use PryTax\Http\Requests\Accounts\OpeningBalanceRequest;
use PryTax\Http\Requests\Accounts\UpdateOpeningBalanceRequest;
use PryTax\Http\Resources\AccountOpeningBalanceCollection;
use PryTax\Http\Resources\AccountOpeningBalanceResource;
use PryTax\Policies\AccountOpeningBalancePolicy;
use PryTax\Repositories\AccountOpeningBalanceRepository;

/**
 * Class AccountOpeningBalanceController.
 *
 * Opening balances of an account: `profiles/{profile}/accounts/{account}/opening-balances`.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Controllers
 */
class AccountOpeningBalanceController extends TenantController
{
    /**
     * Resource, which wraps a model.
     *
     * @var string
     */
    protected string $resourceClass = AccountOpeningBalanceResource::class;

    /**
     * Collection, which wraps a list of models.
     *
     * @var string
     */
    protected string $collectionClass = AccountOpeningBalanceCollection::class;

    /**
     * Operations, which the controller handles, mapped to the classes of their requests.
     *
     * @var array
     */
    protected array $operations = [
        'index' => IndexRequest::class,
        'show' => ShowRequest::class,
        'store' => OpeningBalanceRequest::class,
        'update' => UpdateOpeningBalanceRequest::class,
        'destroy' => DestroyRequest::class,
    ];

    /**
     * Route parameters of the models' parents, from the outermost to the innermost.
     *
     * @var array<int, string>
     */
    protected array $parents = ['profile', 'account'];

    /**
     * AccountOpeningBalanceController constructor.
     *
     * @param AccountOpeningBalancePolicy $policy
     * @param AccountOpeningBalanceRepository $repository
     */
    public function __construct(AccountOpeningBalancePolicy $policy, AccountOpeningBalanceRepository $repository)
    {
        parent::__construct($policy, $repository);
    }
}
