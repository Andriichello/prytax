<?php

namespace PryTax\Queries;

use Core\Queries\BaseQuery;
use Illuminate\Database\Eloquent\Model;

/**
 * Class TenantQuery.
 *
 * Query of a model of a profile. Its records are limited to the current profile by BelongsToProfile's global scope,
 * and every user of the organization sees every profile (design.md §0), so the index scope only shuts out guests.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Queries
 */
class TenantQuery extends BaseQuery
{
    /**
     * Apply index query conditions for a given user: none for a guest, all of the profile for anybody else.
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

    /**
     * Only the active records.
     *
     * @return static
     */
    public function active(): static
    {
        $this->where($this->qualifyColumn('is_active'), true);

        return $this;
    }
}
