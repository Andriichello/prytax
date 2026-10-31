<?php

namespace PryTax\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Class ArchiveRefusedException.
 *
 * One of several backup archives, which can't be imported into a new organization, so neither it nor the others are:
 * which of them (its position, as given), and why.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Exceptions
 */
class ArchiveRefusedException extends RuntimeException
{
    /**
     * ArchiveRefusedException constructor.
     *
     * @param int $archive The archive's position among those given.
     * @param string $message Why, in English, as `__()` translates it.
     * @param Throwable|null $previous
     */
    public function __construct(public readonly int $archive, string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
