<?php

namespace PryTax\Enums;

use Core\Enums\Interfaces\BaseEnumInterface;
use Core\Enums\Traits\BaseEnum;

/**
 * Enum PaymentKind.
 *
 * Kind of a receipt: of an invoice (a virtual one for a payment without an invoice, design.md §4.2), or a synthetic
 * FX gain of a currency sale.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Enums
 */
enum PaymentKind: string implements BaseEnumInterface
{
    use BaseEnum;

    case Invoice = 'invoice';
    case FxGain = 'fx_gain';
}
