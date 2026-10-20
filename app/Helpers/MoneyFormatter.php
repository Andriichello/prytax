<?php

namespace PryTax\Helpers;

use Brick\Math\BigNumber;
use PryTax\Enums\MoneyFormat;

/**
 * Class MoneyFormatter.
 *
 * Formats canonical amounts (`1234.99`) for people, by a profile's money format (design.md §10): the invoice PDFs
 * and the exports use it, while the API never formats money. The ISO code follows the amount, and UAH is `₴`.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Helpers
 */
class MoneyFormatter
{
    /**
     * MoneyFormatter constructor.
     *
     * @param MoneyFormat $format
     */
    public function __construct(
        public readonly MoneyFormat $format = MoneyFormat::SpaceDot
    ) {}

    /**
     * Make a formatter of the format's value, or of the default one for an unknown value.
     *
     * @param MoneyFormat|string|null $format
     *
     * @return static
     */
    public static function of(MoneyFormat|string|null $format): static
    {
        if (is_string($format)) {
            $format = MoneyFormat::tryFrom($format);
        }

        return new static($format ?? MoneyFormat::SpaceDot);
    }

    /**
     * Format the amount with two decimals, such as `1 234.99`; a negative one starts with a minus sign (U+2212).
     *
     * @param BigNumber|int|string|null $amount
     * @param int $scale
     *
     * @return string
     */
    public function amount(BigNumber|int|string|null $amount, int $scale = 2): string
    {
        $value = Decimal::round($amount, $scale);
        $negative = $value->isNegative();
        [$whole, $fraction] = array_pad(explode('.', (string) $value->abs()), 2, '');

        $group = $this->format->groupSeparator();

        if ($group !== '') {
            $groups = [];

            while (strlen($whole) > 3) {
                array_unshift($groups, substr($whole, -3));
                $whole = substr($whole, 0, -3);
            }

            array_unshift($groups, $whole);
            $whole = implode($group, $groups);
        }

        $formatted = $fraction === '' ? $whole : $whole . $this->format->decimalSeparator() . $fraction;

        return ($negative ? "\u{2212}" : '') . $formatted;
    }

    /**
     * Format the amount with its currency after it: `3 892.50 USD`, and `1 234.99 ₴` for UAH.
     *
     * @param BigNumber|int|string|null $amount
     * @param string $currency
     *
     * @return string
     */
    public function money(BigNumber|int|string|null $amount, string $currency): string
    {
        $symbol = strtoupper($currency) === 'UAH' ? '₴' : strtoupper($currency);

        return $this->amount($amount) . ' ' . $symbol;
    }

    /**
     * Parse an amount, which a person typed, into its canonical form: the last `.` or `,` is the decimal
     * separator, and grouping characters (spaces and the separators before the last one) are ignored, so
     * `2 851,99`, `2,851.99`, `2.851,99` and `2851.99` all give `2851.99`.
     *
     * @param string $input
     *
     * @return string|null Null, when the input isn't an amount.
     */
    public static function parse(string $input): ?string
    {
        $value = preg_replace('/[\s\x{00A0}\x{202F}\x{2009}\']/u', '', trim($input));
        $value = str_replace("\u{2212}", '-', (string) $value);

        if ($value === '' || !preg_match('/^-?[0-9.,]+$/', $value)) {
            return null;
        }

        $lastSeparator = max(strrpos($value, '.'), strrpos($value, ','));

        if ($lastSeparator !== false) {
            $whole = str_replace(['.', ','], '', substr($value, 0, $lastSeparator));
            $fraction = substr($value, $lastSeparator + 1);

            $value = $whole . '.' . $fraction;
        }

        if (!preg_match('/^-?[0-9]*\.?[0-9]+$/', $value)) {
            return null;
        }

        return $value;
    }
}
