<?php

namespace PryTax\Enums;

use Core\Enums\Interfaces\BaseEnumInterface;
use Core\Enums\Traits\BaseEnum;

/**
 * Enum MovementDirection.
 *
 * Direction of a non-taxable movement of an account.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Enums
 */
enum MovementDirection: string implements BaseEnumInterface
{
    use BaseEnum;

    case In = 'in';
    case Out = 'out';
}
