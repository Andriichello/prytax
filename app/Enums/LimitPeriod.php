<?php

namespace PryTax\Enums;

use Core\Enums\Interfaces\BaseEnumInterface;
use Core\Enums\Traits\BaseEnum;

/**
 * Enum LimitPeriod.
 *
 * Period of an account's limit.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Enums
 */
enum LimitPeriod: string implements BaseEnumInterface
{
    use BaseEnum;

    case Month = 'month';
    case Quarter = 'quarter';
    case Year = 'year';
}
