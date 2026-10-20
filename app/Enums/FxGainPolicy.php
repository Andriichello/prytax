<?php

namespace PryTax\Enums;

use Core\Enums\Interfaces\BaseEnumInterface;
use Core\Enums\Traits\BaseEnum;

/**
 * Enum FxGainPolicy.
 *
 * Whether a positive FX difference of a currency sale is income (ДПС position, ЗІР 107.01.03).
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Enums
 */
enum FxGainPolicy: string implements BaseEnumInterface
{
    use BaseEnum;

    case Ignore = 'ignore';
    case Include = 'include';
}
