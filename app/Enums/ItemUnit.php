<?php

namespace PryTax\Enums;

use Core\Enums\Interfaces\BaseEnumInterface;
use Core\Enums\Traits\BaseEnum;

/**
 * Enum ItemUnit.
 *
 * Unit of an invoice item.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Enums
 */
enum ItemUnit: string implements BaseEnumInterface
{
    use BaseEnum;

    case Hour = 'hour';
    case Fixed = 'fixed';
    case Item = 'item';
}
