<?php

namespace PryTax\Enums;

use Core\Enums\Interfaces\BaseEnumInterface;
use Core\Enums\Traits\BaseEnum;

/**
 * Enum SettingSource.
 *
 * Level, from which an invoice setting comes (design.md §4.1): the invoice's own, the client's, the profile's, or the
 * built-in default.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Enums
 */
enum SettingSource: string implements BaseEnumInterface
{
    use BaseEnum;

    case Invoice = 'invoice';
    case Client = 'client';
    case Profile = 'profile';
    case Default = 'default';
}
