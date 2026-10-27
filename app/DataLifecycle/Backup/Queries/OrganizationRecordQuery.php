<?php

namespace PryTax\DataLifecycle\Backup\Queries;

use Core\Queries\BaseQuery;
use Illuminate\Database\Eloquent\Model;

/**
 * Class OrganizationRecordQuery.
 *
 * Query of the module's records of an organization (policies, runs, backups): BelongsToOrganization limits them to
 * the current organization, and the index scope shuts out guests; the roles decide the rest.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Backup\Queries
 */
class OrganizationRecordQuery extends BaseQuery
{
    /**
     * Apply index query conditions for a given user: none for a guest.
     *
     * @param Model|null $user
     * @param array $params
     *
     * @return static
     */
    public function index(?Model $user, array $params = []): static
    {
        if (!$user) {
            $this->whereRaw('1 = 0');
        }

        return $this;
    }
}
