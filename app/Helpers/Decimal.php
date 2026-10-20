<?php

namespace PryTax\Helpers;

use Brick\Math\BigDecimal;
use Brick\Math\BigNumber;
use Brick\Math\RoundingMode;

/**
 * Class Decimal.
 *
 * Money arithmetic without floats (design.md §2.4): amounts are `DECIMAL(15,2)` and rates `DECIMAL(12,4)`, which
 * the database and the API carry as strings. Every rounding is ROUND_HALF_UP.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Helpers
 */
final class Decimal
{
    /**
     * Get the value as a BigDecimal, with null as zero.
     *
     * @param BigNumber|int|string|null $value
     *
     * @return BigDecimal
     */
    public static function of(BigNumber|int|string|null $value): BigDecimal
    {
        if ($value === null || $value === '') {
            return BigDecimal::zero();
        }

        return BigDecimal::of($value);
    }

    /**
     * Get zero.
     *
     * @return BigDecimal
     */
    public static function zero(): BigDecimal
    {
        return BigDecimal::zero();
    }

    /**
     * Round the value half up to the scale.
     *
     * @param BigNumber|int|string|null $value
     * @param int $scale
     *
     * @return BigDecimal
     */
    public static function round(BigNumber|int|string|null $value, int $scale = 2): BigDecimal
    {
        return self::of($value)->toScale($scale, RoundingMode::HalfUp);
    }

    /**
     * Get the value as an amount: a string with two decimals, such as "516.27".
     *
     * @param BigNumber|int|string|null $value
     *
     * @return string
     */
    public static function money(BigNumber|int|string|null $value): string
    {
        return (string) self::round($value);
    }

    /**
     * Get the value as an amount, or null, when it is null.
     *
     * @param BigNumber|int|string|null $value
     *
     * @return string|null
     */
    public static function moneyOrNull(BigNumber|int|string|null $value): ?string
    {
        return $value === null ? null : self::money($value);
    }

    /**
     * Get the value as a rate: a string with four decimals, such as "42.2297".
     *
     * @param BigNumber|int|string|null $value
     *
     * @return string
     */
    public static function rate(BigNumber|int|string|null $value): string
    {
        return (string) self::round($value, 4);
    }

    /**
     * Get ROUND_HALF_UP(value × factor, scale), such as a tax of an amount or an amount in UAH.
     *
     * @param BigNumber|int|string|null $value
     * @param BigNumber|int|string|null $factor
     * @param int $scale
     *
     * @return BigDecimal
     */
    public static function times(
        BigNumber|int|string|null $value,
        BigNumber|int|string|null $factor,
        int $scale = 2
    ): BigDecimal {
        return self::of($value)->multipliedBy(self::of($factor))->toScale($scale, RoundingMode::HalfUp);
    }

    /**
     * Get ROUND_HALF_UP(value / divisor, scale), or zero for a zero divisor.
     *
     * @param BigNumber|int|string|null $value
     * @param BigNumber|int|string|null $divisor
     * @param int $scale
     *
     * @return BigDecimal
     */
    public static function divide(
        BigNumber|int|string|null $value,
        BigNumber|int|string|null $divisor,
        int $scale = 2
    ): BigDecimal {
        $divisor = self::of($divisor);

        if ($divisor->isZero()) {
            return BigDecimal::zero()->toScale($scale);
        }

        return self::of($value)->dividedBy($divisor, $scale, RoundingMode::HalfUp);
    }

    /**
     * Get the sum of the values.
     *
     * @param iterable<BigNumber|int|string|null> $values
     *
     * @return BigDecimal
     */
    public static function sum(iterable $values): BigDecimal
    {
        $sum = BigDecimal::zero();

        foreach ($values as $value) {
            $sum = $sum->plus(self::of($value));
        }

        return $sum;
    }

    /**
     * Get the larger of the two values.
     *
     * @param BigNumber|int|string|null $first
     * @param BigNumber|int|string|null $second
     *
     * @return BigDecimal
     */
    public static function max(BigNumber|int|string|null $first, BigNumber|int|string|null $second): BigDecimal
    {
        $first = self::of($first);
        $second = self::of($second);

        return $first->isGreaterThanOrEqualTo($second) ? $first : $second;
    }

    /**
     * Get the smaller of the two values.
     *
     * @param BigNumber|int|string|null $first
     * @param BigNumber|int|string|null $second
     *
     * @return BigDecimal
     */
    public static function min(BigNumber|int|string|null $first, BigNumber|int|string|null $second): BigDecimal
    {
        $first = self::of($first);
        $second = self::of($second);

        return $first->isLessThanOrEqualTo($second) ? $first : $second;
    }

    /**
     * Get the part as a percentage of the whole, with one decimal, such as "18.2", or "0.0" for a zero whole.
     *
     * @param BigNumber|int|string|null $part
     * @param BigNumber|int|string|null $whole
     *
     * @return string
     */
    public static function percent(BigNumber|int|string|null $part, BigNumber|int|string|null $whole): string
    {
        return (string) self::divide(self::of($part)->multipliedBy(100), $whole, 1);
    }
}
