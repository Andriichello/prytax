<?php

namespace PryTax\DataLifecycle\Backup;

use RuntimeException;

/**
 * Class ImportException.
 *
 * An archive, which can't be imported: of another format or a newer version, not restorable, damaged, of a tenant,
 * which the organization has already, or one, whose figures differ after the import (backups.md §5.1). Nothing is
 * written then.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Backup
 */
class ImportException extends RuntimeException
{
    //
}
