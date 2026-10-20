<?php

namespace PryTax\Enums;

use Core\Enums\Interfaces\BaseEnumInterface;
use Core\Enums\Traits\BaseEnum;

/**
 * Enum AccountKind.
 *
 * Kind of an account. Only a `fop_bank` one counts as money that reached Ukraine; a `personal` one is out of the business (limits, income, repatriation).
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Enums
 */
enum AccountKind: string implements BaseEnumInterface
{
    use BaseEnum;

    case Platform = 'platform';
    case FopBank = 'fop_bank';
    case ForeignBank = 'foreign_bank';
    case Personal = 'personal';
}
