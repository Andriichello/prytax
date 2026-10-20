<?php

namespace PryTax\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Class PeriodLockedException.
 *
 * Thrown when a write falls into a quarter, whose declaration was submitted, and the request carries no
 * `correction_note` (design.md §8.3). It answers with 409.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Exceptions
 */
class PeriodLockedException extends ConflictHttpException
{
    //
}
