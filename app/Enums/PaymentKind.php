<?php

namespace PryTax\Enums;

use Core\Enums\Interfaces\BaseEnumInterface;
use Core\Enums\Traits\BaseEnum;

/**
 * Enum PaymentKind.
 *
 * Kind of a receipt: for an invoice, an advance, other taxable income, or a synthetic FX gain.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Enums
 */
enum PaymentKind: string implements BaseEnumInterface
{
    use BaseEnum;

    case Invoice = 'invoice';
    case Advance = 'advance';
    case Other = 'other';
    case FxGain = 'fx_gain';
}
