<?php

namespace PryTax\DataLifecycle\Backup\Internal;

use Exception;

/**
 * Class DryRunRollback.
 *
 * Thrown inside a dry run's transaction, once the import is written and verified, to roll it back.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Backup\Internal
 */
final class DryRunRollback extends Exception
{
    //
}
