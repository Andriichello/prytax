<?php

namespace PryTax\Enums;

use Core\Enums\Interfaces\BaseEnumInterface;
use Core\Enums\Traits\BaseEnum;

/**
 * Enum InvoiceStatus.
 *
 * Status of an invoice: draft → ready (frozen) → sent → paid, and cancelled from ready or sent. A paid invoice,
 * which its receipts no longer cover, returns to sent (or ready).
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Enums
 */
enum InvoiceStatus: string implements BaseEnumInterface
{
    use BaseEnum;

    case Draft = 'draft';
    case Ready = 'ready';
    case Sent = 'sent';
    case Paid = 'paid';
    case Cancelled = 'cancelled';
}
