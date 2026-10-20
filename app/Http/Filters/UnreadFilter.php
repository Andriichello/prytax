<?php

namespace PryTax\Http\Filters;

use Core\Enums\FilterOperation;
use Core\Http\Filters\BaseFilter;
use Core\Queries\BaseQuery;
use InvalidArgumentException;
use PryTax\Queries\AlertQuery;

/**
 * Class UnreadFilter.
 *
 * Filters notifications by whether the signed-in user read them: `filter[unread]=1` lists the unread ones,
 * `filter[unread]=0` the read ones.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Filters
 */
class UnreadFilter extends BaseFilter
{
    /**
     * Add the filter's condition to the query.
     *
     * @param BaseQuery $query
     * @param string $column
     * @param mixed $value
     * @param string $boolean
     *
     * @return void
     * @throws InvalidArgumentException
     */
    public function apply(BaseQuery $query, string $column, mixed $value, string $boolean = 'and'): void
    {
        $user = auth()->user();

        if ($this->operation !== FilterOperation::EQ || !$query instanceof AlertQuery || !$user) {
            throw new InvalidArgumentException("The 'unread' filter takes true or false, such as filter[unread]=1.");
        }

        if (filter_var($value, FILTER_VALIDATE_BOOLEAN)) {
            $query->unreadBy($user);

            return;
        }

        $query->whereExists(fn ($reads) => $reads->from('alert_reads')
            ->whereColumn('alert_reads.alert_id', $query->qualifyColumn('id'))
            ->where('alert_reads.user_id', $user->getKey()));
    }
}
