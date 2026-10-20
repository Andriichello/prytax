<?php

namespace PryTax\Enums;

use Core\Enums\Interfaces\BaseEnumInterface;
use Core\Enums\Traits\BaseEnum;

/**
 * Enum TaxIdKind.
 *
 * Kind of a profile's tax id: РНОКПП, or the passport of a person who refused it.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Enums
 */
enum TaxIdKind: string implements BaseEnumInterface
{
    use BaseEnum;

    case Rnokpp = 'rnokpp';
    case Passport = 'passport';
}
