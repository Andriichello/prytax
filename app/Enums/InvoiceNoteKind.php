<?php

namespace PryTax\Enums;

use Core\Enums\Interfaces\BaseEnumInterface;
use Core\Enums\Traits\BaseEnum;

/**
 * Enum InvoiceNoteKind.
 *
 * Where an invoice's note prints in the payment card of its PDF: one about the payment under the payment details
 * (PAYMENT NOTE), another one on the right, under the act of acceptance (OTHER NOTES).
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Enums
 */
enum InvoiceNoteKind: string implements BaseEnumInterface
{
    use BaseEnum;

    case Payment = 'payment';
    case Other = 'other';
}
