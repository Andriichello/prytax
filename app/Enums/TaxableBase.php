<?php

namespace PryTax\Enums;

use Core\Enums\Interfaces\BaseEnumInterface;
use Core\Enums\Traits\BaseEnum;

/**
 * Enum TaxableBase.
 *
 * What a receipt's taxable base is: the invoiced amount, or the amount actually received (at the user's own risk).
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Enums
 */
enum TaxableBase: string implements BaseEnumInterface
{
    use BaseEnum;

    case Invoiced = 'invoiced';
    case Received = 'received';
}
