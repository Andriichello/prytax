<?php

namespace PryTax\Http\Controllers;

use Core\Http\Requests\Crud\DestroyRequest;
use Core\Http\Requests\Crud\IndexRequest;
use Core\Http\Requests\Crud\ShowRequest;
use PryTax\Http\Requests\Clients\StoreProjectRequest;
use PryTax\Http\Requests\Clients\UpdateProjectRequest;
use PryTax\Http\Resources\ProjectCollection;
use PryTax\Http\Resources\ProjectResource;
use PryTax\Policies\ProjectPolicy;
use PryTax\Repositories\ProjectRepository;

/**
 * Class ProjectController.
 *
 * Projects of a client: `profiles/{profile}/clients/{client}/projects`.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Controllers
 */
class ProjectController extends TenantController
{
    /**
     * Resource, which wraps a model.
     *
     * @var string
     */
    protected string $resourceClass = ProjectResource::class;

    /**
     * Collection, which wraps a list of models.
     *
     * @var string
     */
    protected string $collectionClass = ProjectCollection::class;

    /**
     * Operations, which the controller handles, mapped to the classes of their requests.
     *
     * @var array
     */
    protected array $operations = [
        'index' => IndexRequest::class,
        'show' => ShowRequest::class,
        'store' => StoreProjectRequest::class,
        'update' => UpdateProjectRequest::class,
        'destroy' => DestroyRequest::class,
    ];

    /**
     * Route parameters of the models' parents, from the outermost to the innermost.
     *
     * @var array<int, string>
     */
    protected array $parents = ['profile', 'client'];

    /**
     * ProjectController constructor.
     *
     * @param ProjectPolicy $policy
     * @param ProjectRepository $repository
     */
    public function __construct(ProjectPolicy $policy, ProjectRepository $repository)
    {
        parent::__construct($policy, $repository);
    }
}
