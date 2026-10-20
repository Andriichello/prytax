<?php

namespace PryTax\Queries;

use Carbon\CarbonInterface;

/**
 * Class TaxRuleQuery.
 *
 * Query of tax rules.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Queries
 */
class TaxRuleQuery extends GlobalQuery
{
    /**
     * Only the rules in effect on the date, the latest first.
     *
     * @param CarbonInterface|string $date
     *
     * @return static
     */
    public function effectiveOn(CarbonInterface|string $date): static
    {
        $date = $date instanceof CarbonInterface ? $date->toDateString() : $date;

        $this->where($this->qualifyColumn('effective_from'), '<=', $date)
            ->where(fn ($query) => $query->whereNull($this->qualifyColumn('effective_to'))
                ->orWhere($this->qualifyColumn('effective_to'), '>=', $date))
            ->orderByDesc($this->qualifyColumn('effective_from'));

        return $this;
    }
}
