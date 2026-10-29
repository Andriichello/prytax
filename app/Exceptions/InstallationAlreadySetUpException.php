<?php

namespace PryTax\Exceptions;

use RuntimeException;

/**
 * Class InstallationAlreadySetUpException.
 *
 * Thrown when the first owner of an installation is asked for, but the installation already has a user: from then on
 * users come only from an owner's invitations.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Exceptions
 */
class InstallationAlreadySetUpException extends RuntimeException
{
    //
}
