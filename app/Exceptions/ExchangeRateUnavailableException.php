<?php

namespace PryTax\Exceptions;

use RuntimeException;

/**
 * Class ExchangeRateUnavailableException.
 *
 * Thrown when the NBU rate of a currency on a date can't be fetched. A form then offers a manual rate rather than
 * falling back to a default (design.md §10).
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Exceptions
 */
class ExchangeRateUnavailableException extends RuntimeException
{
    //
}
