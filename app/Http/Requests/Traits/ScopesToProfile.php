<?php

namespace PryTax\Http\Requests\Traits;

use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;
use PryTax\Helpers\CurrentProfile;
use PryTax\Helpers\MoneyFormatter;

/**
 * Trait ScopesToProfile.
 *
 * For requests of a profile's records: rules, which look records up within the profile of the request (never one
 * named in the input), and the normalization of amounts, which people type with either decimal separator and with
 * grouping (`2 851,99`), into canonical strings (`2851.99`) before validation (design.md §10).
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Requests\Traits
 */
trait ScopesToProfile
{
    /**
     * Get id of the profile, in which the request acts.
     *
     * @return int|null
     */
    public function profileId(): ?int
    {
        return CurrentProfile::id();
    }

    /**
     * Rule: the record exists in the table within the request's profile.
     *
     * @param string $table
     *
     * @return Exists
     */
    protected function existsInProfile(string $table): Exists
    {
        return (new Exists($table, 'id'))->where('profile_id', $this->profileId() ?? 0);
    }

    /**
     * Rule: the value is unique in the table's column within the request's profile.
     *
     * @param string $table
     * @param string $column
     *
     * @return Unique
     */
    protected function uniqueInProfile(string $table, string $column): Unique
    {
        return (new Unique($table, $column))->where('profile_id', $this->profileId() ?? 0);
    }

    /**
     * Get the canonical amounts of the given fields of the input: top-level ones, such as `amount`, and fields of
     * lists, such as `items.*.unit_price`. Values, which aren't amounts, are left for the rules to refuse.
     *
     * @param array<int, string> $fields
     *
     * @return array
     */
    protected function normalizedMoney(array $fields): array
    {
        $input = $this->all();
        $changed = [];

        foreach ($fields as $field) {
            if (str_contains($field, '.*.')) {
                [$list, $key] = explode('.*.', $field, 2);

                if (!is_array($input[$list] ?? null)) {
                    continue;
                }

                $items = $changed[$list] ?? $input[$list];

                foreach ($items as $index => $item) {
                    if (is_array($item) && is_string($item[$key] ?? null) && ($parsed = MoneyFormatter::parse($item[$key])) !== null) {
                        $items[$index][$key] = $parsed;
                    }
                }

                $changed[$list] = $items;

                continue;
            }

            if (is_string($input[$field] ?? null) && ($parsed = MoneyFormatter::parse($input[$field])) !== null) {
                $changed[$field] = $parsed;
            }
        }

        return $changed;
    }

    /**
     * Rules of an amount: a decimal with up to two places.
     *
     * @param bool $positive Whether it must be above zero.
     *
     * @return array<int, string>
     */
    protected function moneyRules(bool $positive = true): array
    {
        return ['numeric', 'decimal:0,2', $positive ? 'gt:0' : 'gte:0', 'max:9999999999999'];
    }
}
