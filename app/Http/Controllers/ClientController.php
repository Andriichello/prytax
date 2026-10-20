<?php

namespace PryTax\Http\Controllers;

use Core\Http\Requests\Crud\DestroyRequest;
use Core\Http\Requests\Crud\IndexRequest;
use Core\Http\Requests\Crud\ShowRequest;
use Core\Http\Responses\ApiResponse;
use PryTax\Http\Requests\Clients\StoreClientRequest;
use PryTax\Http\Requests\Clients\UpdateClientRequest;
use PryTax\Http\Resources\ClientCollection;
use PryTax\Http\Resources\ClientResource;
use PryTax\Models\Client;
use PryTax\Policies\ClientPolicy;
use PryTax\Repositories\ClientRepository;
use PryTax\Services\Reports\ClientStats;

/**
 * Class ClientController.
 *
 * Clients of a profile: `profiles/{profile}/clients`, each with the counts of its contracts and invoices
 * (`?append=outstanding` for what it owes), and the numbers of one for its page.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Controllers
 */
class ClientController extends TenantController
{
    /**
     * Resource, which wraps a model.
     *
     * @var string
     */
    protected string $resourceClass = ClientResource::class;

    /**
     * Collection, which wraps a list of models.
     *
     * @var string
     */
    protected string $collectionClass = ClientCollection::class;

    /**
     * Operations, which the controller handles, mapped to the classes of their requests.
     *
     * @var array
     */
    protected array $operations = [
        'index' => IndexRequest::class,
        'show' => ShowRequest::class,
        'store' => StoreClientRequest::class,
        'update' => UpdateClientRequest::class,
        'destroy' => DestroyRequest::class,
        'stats' => ShowRequest::class,
    ];

    /**
     * ClientController constructor.
     *
     * @param ClientPolicy $policy
     * @param ClientRepository $repository
     * @param ClientStats $stats
     */
    public function __construct(
        ClientPolicy $policy,
        ClientRepository $repository,
        protected ClientStats $stats
    ) {
        parent::__construct($policy, $repository);
    }

    /**
     * Handle the `stats` operation: paid all-time, this year and last year, and outstanding, per currency.
     *
     * @param ShowRequest $request
     *
     * @return ApiResponse
     */
    protected function handleStats(ShowRequest $request): ApiResponse
    {
        /** @var Client $client */
        $client = $this->builder($request)->findOrFail($request->getId());

        return ApiResponse::ok(['data' => $this->stats->forClient($client)]);
    }
}
