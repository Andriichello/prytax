<?php

namespace PryTax\Http\Filters;

use Core\Enums\FilterOperation;
use Core\Http\Filters\BaseFilter;
use Core\Queries\BaseQuery;
use InvalidArgumentException;
use PryTax\Enums\InvoiceSource;

/**
 * Class StatusFilter.
 *
 * Filters invoices by status, with `virtual` as a status of its own (design.md §4.2): `filter[status][in]=paid,virtual`
 * matches the paid invoices and the records of payments without an invoice, which are paid too, but never match a
 * status of the invoices.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Filters
 */
class StatusFilter extends BaseFilter
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
            throw new InvalidArgumentException("The 'status' filter takes eq or in, such as filter[status][in]=sent,virtual.");
        }

        $values = array_values(array_filter(array_map('trim', is_array($value) ? $value : explode(',', (string) $value))));
        $statuses = array_values(array_diff($values, [InvoiceSource::Virtual->value]));
        $virtual = in_array(InvoiceSource::Virtual->value, $values, true);
        $source = $query->qualifyColumn('source');
        $status = $query->qualifyColumn('status');

        $query->where(function ($query) use ($statuses, $virtual, $source, $status) {
            if (!empty($statuses)) {
                $query->where(fn ($query) => $query
                    ->whereIn($status, $statuses)
                    ->where($source, '<>', InvoiceSource::Virtual->value));
            }

            if ($virtual) {
                $query->orWhere($source, InvoiceSource::Virtual->value);
            }
        }, boolean: $boolean);
    }
}
