<?php

namespace PryTax\Enums;

use Core\Enums\Interfaces\BaseEnumInterface;
use Core\Enums\Traits\BaseEnum;

/**
 * Enum TaxAllocation.
 *
 * What a tax payment is allocated to: a receipt, a month or a quarter.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Enums
 */
enum TaxAllocation: string implements BaseEnumInterface
{
    use BaseEnum;

    case Payment = 'payment';
    case Month = 'month';
    case Quarter = 'quarter';
}
