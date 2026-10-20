<?php

namespace PryTax\Enums;

use Core\Enums\Interfaces\BaseEnumInterface;
use Core\Enums\Traits\BaseEnum;

/**
 * Enum MoneyFormat.
 *
 * How an amount is displayed: its decimal separator and its thousands grouping, a setting of a profile for the app
 * (`display.money_format`) and for invoices (`invoice.money_format`). The API always carries `1234.99`.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Enums
 */
enum MoneyFormat: string implements BaseEnumInterface
{
    use BaseEnum;

    /**
     * Narrow no-break space (U+202F), which groups thousands, so an amount never wraps.
     *
     * @var string
     */
    public const string NARROW_NO_BREAK_SPACE = "\u{202F}";

    case PlainDot = 'plain_dot';
    case SpaceDot = 'space_dot';
    case CommaDot = 'comma_dot';
    case PlainComma = 'plain_comma';
    case SpaceComma = 'space_comma';
    case DotComma = 'dot_comma';

    /**
     * Get the decimal separator.
     *
     * @return string
     */
    public function decimalSeparator(): string
    {
        return match ($this) {
            self::PlainDot, self::SpaceDot, self::CommaDot => '.',
            self::PlainComma, self::SpaceComma, self::DotComma => ',',
        };
    }

    /**
     * Get the separator of thousands, or an empty string, when they aren't grouped.
     *
     * @return string
     */
    public function groupSeparator(): string
    {
        return match ($this) {
            self::PlainDot, self::PlainComma => '',
            self::SpaceDot, self::SpaceComma => self::NARROW_NO_BREAK_SPACE,
            self::CommaDot => ',',
            self::DotComma => '.',
        };
    }
}
