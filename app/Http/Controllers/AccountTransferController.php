<?php

namespace PryTax\Http\Controllers;

use Core\Http\Requests\Crud\DestroyRequest;
use Core\Http\Requests\Crud\IndexRequest;
use Core\Http\Requests\Crud\ShowRequest;
use Core\Http\Responses\ApiResponse;
use PryTax\Http\Requests\Transfers\ProposeAllocationsRequest;
use PryTax\Http\Requests\Transfers\StoreTransferRequest;
use PryTax\Http\Requests\Transfers\UpdateTransferRequest;
use PryTax\Http\Resources\AccountTransferCollection;
use PryTax\Http\Resources\AccountTransferResource;
use PryTax\Models\Account;
use PryTax\Policies\AccountTransferPolicy;
use PryTax\Repositories\AccountTransferRepository;
use PryTax\Services\Transfers\AllocationProposer;
use PryTax\Services\Transfers\TransferMetrics;

/**
 * Class AccountTransferController.
 *
 * Transfers between a profile's own accounts: `profiles/{profile}/transfers`, with their allocations; a proposal of
 * the receipts a new transfer carries; and the year's losses by route. POSTs take an `Idempotency-Key`.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Controllers
 */
class AccountTransferController extends TenantController
{
    /**
     * Resource, which wraps a model.
     *
     * @var string
     */
    protected string $resourceClass = AccountTransferResource::class;

    /**
     * Collection, which wraps a list of models.
     *
     * @var string
     */
    protected string $collectionClass = AccountTransferCollection::class;

    /**
     * Operations, which the controller handles, mapped to the classes of their requests.
     *
     * @var array
     */
    protected array $operations = [
        'index' => IndexRequest::class,
        'show' => ShowRequest::class,
        'store' => StoreTransferRequest::class,
        'update' => UpdateTransferRequest::class,
        'destroy' => DestroyRequest::class,
        'propose' => ProposeAllocationsRequest::class,
        'stats' => IndexRequest::class,
    ];

    /**
     * AccountTransferController constructor.
     *
     * @param AccountTransferPolicy $policy
     * @param AccountTransferRepository $repository
     * @param AllocationProposer $proposer
     * @param TransferMetrics $metrics
     */
    public function __construct(
        AccountTransferPolicy $policy,
        AccountTransferRepository $repository,
        protected AllocationProposer $proposer,
        protected TransferMetrics $metrics
    ) {
        parent::__construct($policy, $repository);
    }

    /**
     * Handle the `propose` operation: an exact match and a FIFO proposal of the allocations.
     *
     * @param ProposeAllocationsRequest $request
     *
     * @return ApiResponse
     */
    protected function handlePropose(ProposeAllocationsRequest $request): ApiResponse
    {
        $from = Account::query()->findOrFail($request->validated('from_account_id'));

        return ApiResponse::ok(['data' => $this->proposer->propose(
            $from,
            $request->validated('amount_sent'),
            $request->validated('transfer_id'),
        )]);
    }

    /**
     * Handle the `stats` operation: the year's fees and spread in UAH, and the loss of each route.
     *
     * @param IndexRequest $request
     *
     * @return ApiResponse
     */
    protected function handleStats(IndexRequest $request): ApiResponse
    {
        $year = (int) ($request->query('year') ?: now()->year);

        return ApiResponse::ok(['data' => $this->metrics->forYear($this->profileOf($request), max(2000, min(2100, $year)))]);
    }
}
