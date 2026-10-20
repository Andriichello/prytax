<?php

namespace PryTax\Enums;

use Core\Enums\Interfaces\BaseEnumInterface;
use Core\Enums\Traits\BaseEnum;

/**
 * Enum NumberingDateSource.
 *
 * Date, which a `date_sequence` invoice number starts with.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Enums
 */
enum NumberingDateSource: string implements BaseEnumInterface
{
    use BaseEnum;

    case PeriodEnd = 'period_end';
    case IssuedAt = 'issued_at';
}
