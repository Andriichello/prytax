<?php

namespace PryTax\Queries;

/**
 * Class PaymentQuery.
 *
 * Query of receipts.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Queries
 */
class PaymentQuery extends TenantQuery
{
    /**
     * Only the receipts of the year's quarter (by `received_at`, the tax date).
     *
     * @param int $year
     * @param int $quarter
     *
     * @return static
     */
    public function inQuarter(int $year, int $quarter): static
    {
        $this->where($this->qualifyColumn('period_year'), $year)
            ->where($this->qualifyColumn('period_quarter'), $quarter);

        return $this;
    }

    /**
     * Only the receipts from the start of the year to the end of its quarter.
     *
     * @param int $year
     * @param int $quarter
     *
     * @return static
     */
    public function yearToQuarter(int $year, int $quarter): static
    {
        $this->where($this->qualifyColumn('period_year'), $year)
            ->where($this->qualifyColumn('period_quarter'), '<=', $quarter);

        return $this;
    }

    /**
     * Order the receipts by their date, the oldest first.
     *
     * @return static
     */
    public function chronologically(): static
    {
        $this->orderBy($this->qualifyColumn('received_at'))->orderBy($this->qualifyColumn('id'));

        return $this;
    }
}
