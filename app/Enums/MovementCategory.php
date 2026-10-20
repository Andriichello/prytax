<?php

namespace PryTax\Enums;

use Core\Enums\Interfaces\BaseEnumInterface;
use Core\Enums\Traits\BaseEnum;

/**
 * Enum MovementCategory.
 *
 * Category of a non-taxable movement of an account.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Enums
 */
enum MovementCategory: string implements BaseEnumInterface
{
    use BaseEnum;

    case BankFee = 'bank_fee';
    case Interest = 'interest';
    case Expense = 'expense';
    case OwnerWithdrawal = 'owner_withdrawal';
    case Other = 'other';
}
