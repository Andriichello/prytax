<?php

namespace PryTax\Http\Controllers;

use Core\Http\Controllers\CrudController;
use Core\Http\Requests\Crud\DestroyRequest;
use Core\Http\Requests\Crud\IndexRequest;
use Core\Http\Requests\Crud\RestoreRequest;
use Core\Http\Requests\Crud\ShowRequest;
use PryTax\Http\Requests\Profiles\StoreProfileRequest;
use PryTax\Http\Requests\Profiles\UpdateProfileRequest;
use PryTax\Http\Resources\ProfileCollection;
use PryTax\Http\Resources\ProfileResource;
use PryTax\Policies\ProfilePolicy;
use PryTax\Repositories\ProfileRepository;

/**
 * Class ProfileController.
 *
 * Profiles (ФОП) of the organization: `profiles`. Every user of the organization sees every profile; owners create and delete them.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Controllers
 */
class ProfileController extends CrudController
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
        'index' => IndexRequest::class,
        'show' => ShowRequest::class,
        'store' => StoreProfileRequest::class,
        'update' => UpdateProfileRequest::class,
        'destroy' => DestroyRequest::class,
        'restore' => RestoreRequest::class,
    ];

    /**
     * ProfileController constructor.
     *
     * @param ProfilePolicy $policy
     * @param ProfileRepository $repository
     */
    public function __construct(ProfilePolicy $policy, ProfileRepository $repository)
    {
        parent::__construct($policy, $repository);
    }
}
