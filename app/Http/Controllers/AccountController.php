<?php

namespace PryTax\Http\Controllers;

use Core\Http\Requests\Crud\DestroyRequest;
use Core\Http\Requests\Crud\IndexRequest;
use Core\Http\Requests\Crud\ShowRequest;
use Core\Http\Responses\ApiResponse;
use PryTax\Http\Requests\Accounts\AccountStatementRequest;
use PryTax\Http\Requests\Accounts\StoreAccountRequest;
use PryTax\Http\Requests\Accounts\UpdateAccountRequest;
use PryTax\Http\Resources\AccountCollection;
use PryTax\Http\Resources\AccountResource;
use PryTax\Models\Account;
use PryTax\Policies\AccountPolicy;
use PryTax\Repositories\AccountRepository;
use PryTax\Services\Accounts\AccountStatement;

/**
 * Class AccountController.
 *
 * Accounts of a profile: `profiles/{profile}/accounts`, with `?append=balance,limit_progress` for the list, and the
 * statement of one for its screen.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Controllers
 */
class AccountController extends TenantController
{
    /**
     * Resource, which wraps a model.
     *
     * @var string
     */
    protected string $resourceClass = AccountResource::class;

    /**
     * Collection, which wraps a list of models.
     *
     * @var string
     */
    protected string $collectionClass = AccountCollection::class;

    /**
     * Operations, which the controller handles, mapped to the classes of their requests.
     *
     * @var array
     */
    protected array $operations = [
        'index' => IndexRequest::class,
        'show' => ShowRequest::class,
        'store' => StoreAccountRequest::class,
        'update' => UpdateAccountRequest::class,
        'destroy' => DestroyRequest::class,
        'statement' => AccountStatementRequest::class,
    ];

    /**
     * AccountController constructor.
     *
     * @param AccountPolicy $policy
     * @param AccountRepository $repository
     * @param AccountStatement $statements
     */
    public function __construct(
        AccountPolicy $policy,
        AccountRepository $repository,
        protected AccountStatement $statements
    ) {
        parent::__construct($policy, $repository);
    }

    /**
     * Handle the `statement` operation: the account's balance, limit, flows of the year, receipts it holds and
     * history.
     *
     * @param AccountStatementRequest $request
     *
     * @return ApiResponse
     */
    protected function handleStatement(AccountStatementRequest $request): ApiResponse
    {
        /** @var Account $account */
        $account = $this->builder($request)->findOrFail($request->getId());
        $year = (int) ($request->validated('year') ?? now()->year);

        return ApiResponse::ok(['data' => $this->statements->forYear($this->profileOf($request), $account, $year)]);
    }
}
