<?php

namespace PryTax\Enums;

use Core\Enums\Interfaces\BaseEnumInterface;
use Core\Enums\Traits\BaseEnum;

/**
 * Enum AlertCategory.
 *
 * Category of a notification of a profile, as the chips of Сповіщення group them.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Enums
 */
enum AlertCategory: string implements BaseEnumInterface
{
    use BaseEnum;

    case Funds = 'funds';
    case Invoices = 'invoices';
    case Deadlines = 'deadlines';
}
