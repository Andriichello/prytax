<?php

namespace PryTax\Enums;

use Core\Enums\Interfaces\BaseEnumInterface;
use Core\Enums\Traits\BaseEnum;

/**
 * Enum Theme.
 *
 * The look of the screens a user chose. `System` follows the operating system, as a user who chose nothing does.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Enums
 */
enum Theme: string implements BaseEnumInterface
{
    use BaseEnum;

    case Light = 'light';
    case Dark = 'dark';
    case System = 'system';
}
