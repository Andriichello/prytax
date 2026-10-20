<?php

namespace PryTax\Http\Controllers;

use Core\Http\Controllers\CrudController;
use Core\Http\Requests\Crud\IndexRequest;
use Core\Http\Responses\ApiResponse;
use PryTax\Http\Resources\ProfileCollection;
use PryTax\Http\Resources\ProfileResource;
use PryTax\Policies\ProfilePolicy;
use PryTax\Repositories\ProfileRepository;
use PryTax\Services\Reports\PracticeOverview;

/**
 * Class PracticeOverviewController.
 *
 * Огляд практики: one quarter of every active profile of the organization (`overview/{year}/{quarter}`), for the
 * users, who may list profiles.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Controllers
 */
class PracticeOverviewController extends CrudController
{
    /**
     * Resource, which wraps a model.
     *
     * @var string
     */
    protected string $resourceClass = ProfileResource::class;

    /**
     * Collection, which wraps a list of models.
     *
     * @var string
     */
    protected string $collectionClass = ProfileCollection::class;

    /**
     * Operations, which the controller handles, mapped to the classes of their requests.
     *
     * @var array
     */
    protected array $operations = [
        'overview' => IndexRequest::class,
    ];

    /**
     * PracticeOverviewController constructor.
     *
     * @param ProfilePolicy $policy
     * @param ProfileRepository $repository
     * @param PracticeOverview $overview
     */
    public function __construct(
        ProfilePolicy $policy,
        ProfileRepository $repository,
        protected PracticeOverview $overview
    ) {
        parent::__construct($policy, $repository);
    }

    /**
     * Handle the `overview` operation.
     *
     * @param IndexRequest $request
     *
     * @return ApiResponse
     */
    protected function handleOverview(IndexRequest $request): ApiResponse
    {
        return ApiResponse::ok(['data' => $this->overview->forQuarter(
            $request->user(),
            (int) $request->route('year'),
            (int) $request->route('quarter'),
        )]);
    }
}
