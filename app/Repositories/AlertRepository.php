<?php

namespace PryTax\Repositories;

use Core\Models\BaseModel;
use Core\Queries\BaseQuery;
use Illuminate\Http\Request;
use PryTax\Http\Filters\UnreadFilter;
use PryTax\Http\Resources\AlertResource;
use PryTax\Models\Alert;

/**
 * Class AlertRepository.
 *
 * Repository of the notifications of a profile, whose conditions still hold, newest first, each with when the
 * signed-in user read it. Listing them never marks anything read.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Repositories
 */
class AlertRepository extends TenantRepository
{
    /**
     * Model of the repository.
     *
     * @var BaseModel|string
     */
    protected BaseModel|string $model = Alert::class;

    /**
     * Resource, against which includes are checked.
     *
     * @var string
     */
    protected string $resourceClass = AlertResource::class;

    /**
     * Sorts of a list, which asks for none.
     *
     * @var array<string, string>
     */
    protected array $defaultSorts = ['created_at' => 'desc', 'id' => 'desc'];

    /**
     * Filters, besides the fillable columns.
     *
     * @var array
     */
    protected array $allowedFilters = [
        'unread' => UnreadFilter::class,
    ];

    /**
     * Get the query builder for the request: the unresolved notifications with the user's `read_at`.
     *
     * @param Request|null $request
     *
     * @return BaseQuery
     */
    public function builder(?Request $request = null): BaseQuery
    {
        $request ??= request();
        $query = parent::builder($request)->unresolved();

        return $request->user() ? $query->withReadAtOf($request->user()) : $query;
    }
}
