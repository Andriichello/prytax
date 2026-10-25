<?php

namespace PryTax\Http\Controllers;

use Core\Http\Requests\Crud\IndexRequest;
use Core\Http\Requests\Crud\ShowRequest;
use PryTax\Http\Resources\EsvObligationCollection;
use PryTax\Http\Resources\EsvObligationResource;
use PryTax\Policies\EsvObligationPolicy;
use PryTax\Repositories\EsvObligationRepository;

/**
 * Class EsvObligationController.
 *
 * Months of ЄСВ of a profile, which are generated, to read. Marking a month exempt by hand is left out of the
 * «Minimal» phase (design.md §8.3); a month is exempt only where its tax rule makes ЄСВ voluntary.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Controllers
 */
class EsvObligationController extends TenantController
{
    /**
     * Resource, which wraps a model.
     *
     * @var string
     */
    protected string $resourceClass = EsvObligationResource::class;

    /**
     * Collection, which wraps a list of models.
     *
     * @var string
     */
    protected string $collectionClass = EsvObligationCollection::class;

    /**
     * Operations, which the controller handles, mapped to the classes of their requests.
     *
     * @var array
     */
    protected array $operations = [
        'index' => IndexRequest::class,
        'show' => ShowRequest::class,
    ];

    /**
     * EsvObligationController constructor.
     *
     * @param EsvObligationPolicy $policy
     * @param EsvObligationRepository $repository
     */
    public function __construct(EsvObligationPolicy $policy, EsvObligationRepository $repository)
    {
        parent::__construct($policy, $repository);
    }
}
