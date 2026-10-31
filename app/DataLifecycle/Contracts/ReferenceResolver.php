<?php

namespace PryTax\DataLifecycle\Contracts;

/**
 * Interface ReferenceResolver.
 *
 * Reference rows, which an archive references and this database lacks (backups.md §5.1, step 2): the importer never
 * inserts them from the archive, but the app may find or make one where it can tell the true value itself, such as
 * a public exchange rate fetched again on a new installation. Without that, the import is refused.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Contracts
 */
interface ReferenceResolver
{
    /**
     * Get the id of the reference row, which matches the archive's row, found or made, or null, when it can't be.
     *
     * @param string $table
     * @param array<string, string|null> $row The archive's row, as its CSV holds it.
     *
     * @return int|null
     */
    public function resolve(string $table, array $row): ?int;
}
