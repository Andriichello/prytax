<?php

namespace PryTax\Enums;

use Core\Enums\Interfaces\BaseEnumInterface;
use Core\Enums\Traits\BaseEnum;

/**
 * Enum TaxPaymentStatus.
 *
 * Status of a tax payment. A sent one is pending and doesn't reduce the debt; a failed one is ignored.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Enums
 */
enum TaxPaymentStatus: string implements BaseEnumInterface
{
    use BaseEnum;

    case Sent = 'sent';
    case Paid = 'paid';
    case Failed = 'failed';
}
