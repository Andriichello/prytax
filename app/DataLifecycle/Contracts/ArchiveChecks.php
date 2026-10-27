<?php

namespace PryTax\DataLifecycle\Contracts;

use Illuminate\Database\ConnectionInterface;
use PryTax\DataLifecycle\Backup\RunSpec;

/**
 * Interface ArchiveChecks.
 *
 * Figures of a tenant, which the manifest keeps and an import computes again for the imported tenant (backups.md
 * §2.6, §5.1): any difference fails the import. They are read on the given connection, so they see the run's
 * snapshot, and the import's transaction.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Contracts
 */
interface ArchiveChecks
{
    /**
     * Compute the figures of the tenant, as plain values, which compare by equality.
     *
     * @param int $tenantId
     * @param ConnectionInterface $db
     * @param RunSpec $spec
     *
     * @return array<string, mixed>
     */
    public function compute(int $tenantId, ConnectionInterface $db, RunSpec $spec): array;
}
