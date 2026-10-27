<?php

namespace PryTax\DataLifecycle\Backup\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Class ArchiveImported.
 *
 * A tenant was imported from an archive and committed, so the app can rebuild what it derives (PryTax's
 * notifications).
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Backup\Events
 */
class ArchiveImported
{
    use Dispatchable;

    /**
     * ArchiveImported constructor.
     *
     * @param int $tenantId
     * @param int $organizationId
     */
    public function __construct(
        public readonly int $tenantId,
        public readonly int $organizationId,
    ) {}
}
