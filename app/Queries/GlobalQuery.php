<?php

namespace PryTax\Queries;

use Core\Queries\BaseQuery;
use Illuminate\Database\Eloquent\Model;

/**
 * Class GlobalQuery.
 *
 * Query of a global model (tax rules, holidays, exchange rates), which every signed-in user reads.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Queries
 */
class GlobalQuery extends BaseQuery
{
    /**
     * Apply index query conditions for a given user: none for a guest, all for anybody else.
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
