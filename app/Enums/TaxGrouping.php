<?php

namespace PryTax\Enums;

use Core\Enums\Interfaces\BaseEnumInterface;
use Core\Enums\Traits\BaseEnum;

/**
 * Enum TaxGrouping.
 *
 * Default aggregation of the taxes screen.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Enums
 */
enum TaxGrouping: string implements BaseEnumInterface
{
    use BaseEnum;

    case Payment = 'payment';
    case Month = 'month';
    case Quarter = 'quarter';
}
