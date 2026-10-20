<?php

namespace PryTax\Enums;

use Core\Enums\Interfaces\BaseEnumInterface;
use Core\Enums\Traits\BaseEnum;

/**
 * Enum AlertSeverity.
 *
 * Severity of a notification of a profile.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Enums
 */
enum AlertSeverity: string implements BaseEnumInterface
{
    use BaseEnum;

    case Info = 'info';
    case Warning = 'warning';
    case Error = 'error';
}
