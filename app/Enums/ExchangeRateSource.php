<?php

namespace PryTax\Enums;

use Core\Enums\Interfaces\BaseEnumInterface;
use Core\Enums\Traits\BaseEnum;

/**
 * Enum ExchangeRateSource.
 *
 * Source of an exchange rate.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Enums
 */
enum ExchangeRateSource: string implements BaseEnumInterface
{
    use BaseEnum;

    case Nbu = 'nbu';
    case Manual = 'manual';
}
