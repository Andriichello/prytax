<?php

namespace PryTax\Http\Filters;

use Core\Enums\FilterOperation;
use Core\Http\Filters\BaseFilter;
use Core\Queries\BaseQuery;
use InvalidArgumentException;
use PryTax\Queries\InvoiceQuery;

/**
 * Class PeriodFilter.
 *
 * Filters invoices by the periods of their issue date, any mix of years, quarters and months (design.md §10):
 * `filter[period][in]=2026-Q3,2026-10`. Each value is `2026`, `2026-Q4` or `2026-10`; an invoice matches any of them.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Filters
 */
class PeriodFilter extends BaseFilter
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
        if (!in_array($this->operation, [FilterOperation::EQ, FilterOperation::IN], true) || !empty($this->flags)) {
            throw new InvalidArgumentException("The 'period' filter takes eq or in, such as filter[period][in]=2026-Q4,2026-10.");
        }

        if (!$query instanceof InvoiceQuery) {
            throw new InvalidArgumentException("The 'period' filter applies to invoices only.");
        }

        $periods = array_values(array_filter(array_map('trim', is_array($value) ? $value : explode(',', (string) $value))));

        $query->issuedInPeriods($periods, $boolean);
    }
}
