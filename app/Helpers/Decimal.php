<?php

namespace PryTax\Helpers;

use Brick\Math\BigDecimal;
use Brick\Math\BigNumber;
use Brick\Math\RoundingMode;

/**
 * Class Decimal.
 *
 * Money arithmetic without floats (design.md §2.4): amounts are `DECIMAL(15,2)` and rates `DECIMAL(12,4)`, which
 * the database and the API carry as strings. Every rounding is ROUND_HALF_UP. SQLite (the desktop app) returns them
 * as floats, which are taken as the decimal they stand for: a float must never reach an `int|string` parameter,
 * which PHP truncates it to an int for.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Helpers
 */
final class Decimal
{
    /**
     * Get the value as a BigDecimal, with null as zero.
     *
     * @param BigNumber|int|float|string|null $value
     *
     * @return BigDecimal
     */
    public static function of(BigNumber|int|float|string|null $value): BigDecimal
    {
        if ($value === null || $value === '') {
            return BigDecimal::zero();
        }

        if (is_float($value)) {
            // Six places are past any column's scale, and drop the float's error, as of a SQLite SUM
            $value = rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
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
     * @param BigNumber|int|float|string|null $value
     * @param int $scale
     *
     * @return BigDecimal
     */
    public static function round(BigNumber|int|float|string|null $value, int $scale = 2): BigDecimal
    {
        return self::of($value)->toScale($scale, RoundingMode::HalfUp);
    }

    /**
     * Get the value as an amount: a string with two decimals, such as "516.27".
     *
     * @param BigNumber|int|float|string|null $value
     *
     * @return string
     */
    public static function money(BigNumber|int|float|string|null $value): string
    {
        return (string) self::round($value);
    }

    /**
     * Get the value as an amount, or null, when it is null.
     *
     * @param BigNumber|int|float|string|null $value
     *
     * @return string|null
     */
    public static function moneyOrNull(BigNumber|int|float|string|null $value): ?string
    {
        return $value === null ? null : self::money($value);
    }

    /**
     * Get the value as a rate: a string with four decimals, such as "42.2297".
     *
     * @param BigNumber|int|float|string|null $value
     *
     * @return string
     */
    public static function rate(BigNumber|int|float|string|null $value): string
    {
        return (string) self::round($value, 4);
    }

    /**
     * Get ROUND_HALF_UP(value × factor, scale), such as a tax of an amount or an amount in UAH.
     *
     * @param BigNumber|int|float|string|null $value
     * @param BigNumber|int|float|string|null $factor
     * @param int $scale
     *
     * @return BigDecimal
     */
    public static function times(
        BigNumber|int|float|string|null $value,
        BigNumber|int|float|string|null $factor,
        int $scale = 2
    ): BigDecimal {
        return self::of($value)->multipliedBy(self::of($factor))->toScale($scale, RoundingMode::HalfUp);
    }

    /**
     * Get ROUND_HALF_UP(value / divisor, scale), or zero for a zero divisor.
     *
     * @param BigNumber|int|float|string|null $value
     * @param BigNumber|int|float|string|null $divisor
     * @param int $scale
     *
     * @return BigDecimal
     */
    public static function divide(
        BigNumber|int|float|string|null $value,
        BigNumber|int|float|string|null $divisor,
        int $scale = 2
    ): BigDecimal {
        $divisor = self::of($divisor);

        if ($divisor->isZero()) {
            return BigDecimal::zero()->toScale($scale);
        }

        return self::of($value)->dividedBy($divisor, $scale, RoundingMode::HalfUp);
    }

    /**
     * Split an amount into shares in proportion to the weights, to the kopeck, by the largest remainder: each share
     * is first rounded down, and the kopecks left go to the shares with the largest remainders (the first on a tie),
     * so the shares always add up to the amount.
     *
     * @param BigNumber|int|float|string|null $amount
     * @param array<int|string, BigNumber|int|float|string|null> $weights
     *
     * @return array<int|string, string> The shares, as money strings, keyed as the weights.
     */
    public static function split(BigNumber|int|float|string|null $amount, array $weights): array
    {
        $kopecks = self::of($amount)->toScale(2, RoundingMode::HalfUp)->getUnscaledValue();
        $total = self::sum($weights);

        if ($total->isZero()) {
            return array_map(fn () => '0.00', $weights);
        }

        $shares = [];
        $remainders = [];

        foreach ($weights as $key => $weight) {
            $exact = self::of($weight)->multipliedBy($kopecks)->dividedBy($total, 12, RoundingMode::Down);
            $shares[$key] = $exact->toScale(0, RoundingMode::Down);
            $remainders[$key] = $exact->minus($shares[$key]);
        }

        $left = BigDecimal::of($kopecks)->minus(self::sum($shares))->toInt();
        $order = array_keys($remainders);
        usort($order, fn ($first, $second) => $remainders[$second]->compareTo($remainders[$first])
            ?: array_search($first, array_keys($weights), true) <=> array_search($second, array_keys($weights), true));

        foreach (array_slice($order, 0, $left) as $key) {
            $shares[$key] = $shares[$key]->plus(1);
        }

        return array_map(fn (BigDecimal $share) => self::money($share->dividedBy(100, 2)), $shares);
    }

    /**
     * Get the sum of the values.
     *
     * @param iterable<BigNumber|int|float|string|null> $values
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
     * @param BigNumber|int|float|string|null $first
     * @param BigNumber|int|float|string|null $second
     *
     * @return BigDecimal
     */
    public static function max(BigNumber|int|float|string|null $first, BigNumber|int|float|string|null $second): BigDecimal
    {
        $first = self::of($first);
        $second = self::of($second);

        return $first->isGreaterThanOrEqualTo($second) ? $first : $second;
    }

    /**
     * Get the smaller of the two values.
     *
     * @param BigNumber|int|float|string|null $first
     * @param BigNumber|int|float|string|null $second
     *
     * @return BigDecimal
     */
    public static function min(BigNumber|int|float|string|null $first, BigNumber|int|float|string|null $second): BigDecimal
    {
        $first = self::of($first);
        $second = self::of($second);

        return $first->isLessThanOrEqualTo($second) ? $first : $second;
    }

    /**
     * Get the part as a percentage of the whole, with one decimal by default, such as "18.2", or zero for a zero
     * whole.
     *
     * @param BigNumber|int|float|string|null $part
     * @param BigNumber|int|float|string|null $whole
     * @param int $scale
     *
     * @return string
     */
    public static function percent(
        BigNumber|int|float|string|null $part,
        BigNumber|int|float|string|null $whole,
        int $scale = 1
    ): string {
        return (string) self::divide(self::of($part)->multipliedBy(100), $whole, $scale);
    }
}
