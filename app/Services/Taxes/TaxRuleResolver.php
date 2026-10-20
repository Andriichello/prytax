<?php

namespace PryTax\Services\Taxes;

use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;
use PryTax\Models\TaxRule;

/**
 * Class TaxRuleResolver.
 *
 * The tax rule in effect on a date: the latest one, which started on or before it and hasn't ended.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Services\Taxes
 */
class TaxRuleResolver
{
    /**
     * Rules, which were resolved, by their date.
     *
     * @var array<string, TaxRule>
     */
    protected array $resolved = [];

    /**
     * Get the rule in effect on the date.
     *
     * @param CarbonInterface $date
     * @param string $field Field of the error, when there is no rule.
     *
     * @return TaxRule
     * @throws ValidationException When no rule is in effect on the date.
     */
    public function forDate(CarbonInterface $date, string $field = 'date'): TaxRule
    {
        $key = $date->toDateString();

        if (isset($this->resolved[$key])) {
            return $this->resolved[$key];
        }

        $rule = TaxRule::query()->effectiveOn($date)->first();

        if (!$rule) {
            throw ValidationException::withMessages([
                $field => [__('No tax rules are in effect on :date.', ['date' => $date->format('d.m.Y')])],
            ]);
        }

        return $this->resolved[$key] = $rule;
    }

    /**
     * Get the rule in effect on the date, or null.
     *
     * @param CarbonInterface $date
     *
     * @return TaxRule|null
     */
    public function find(CarbonInterface $date): ?TaxRule
    {
        return $this->resolved[$date->toDateString()] ??= TaxRule::query()->effectiveOn($date)->first();
    }
}
