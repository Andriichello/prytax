<?php

namespace PryTax\Enums;

use Core\Enums\Interfaces\BaseEnumInterface;
use Core\Enums\Traits\BaseEnum;

/**
 * Enum LimitWindow.
 *
 * Window of an account's limit: the current calendar period, or the period up to today.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Enums
 */
enum LimitWindow: string implements BaseEnumInterface
{
    use BaseEnum;

    case Calendar = 'calendar';
    case Rolling = 'rolling';
}
