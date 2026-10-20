<?php

namespace PryTax\Repositories;

use Core\Models\BaseModel;
use Core\Queries\BaseQuery;
use Illuminate\Http\Request;
use PryTax\Http\Resources\ClientResource;
use PryTax\Models\Client;

/**
 * Class ClientRepository.
 *
 * Repository of the clients of a profile, each with the counts of its contracts and invoices.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Repositories
 */
class ClientRepository extends TenantRepository
{
    /**
     * Model of the repository.
     *
     * @var BaseModel|string
     */
    protected BaseModel|string $model = Client::class;

    /**
     * Whether a write of the repository's records can change the profile's notifications, so they are synced after.
     *
     * @var bool
     */
    protected bool $syncsAlerts = true;

    /**
     * Resource, against which includes are checked.
     *
     * @var string
     */
    protected string $resourceClass = ClientResource::class;

    /**
     * Sorts of a list, which asks for none.
     *
     * @var array<string, string>
     */
    protected array $defaultSorts = ['is_active' => 'desc', 'name' => 'asc'];

    /**
     * Get the query builder for the request, with the counts of contracts and invoices.
     *
     * @param Request|null $request
     *
     * @return BaseQuery
     */
    public function builder(?Request $request = null): BaseQuery
    {
        return parent::builder($request)->withCount(['contracts', 'invoices']);
    }
}
