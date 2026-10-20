<?php

namespace PryTax\Queries;

/**
 * Class ProfileQuery.
 *
 * Query of profiles, which BelongsToOrganization limits to the current organization, and the index scope to
 * signed-in users. Every user of the organization sees every profile in v1 (design.md §0).
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Queries
 */
class ProfileQuery extends TenantQuery
{
    //
}
