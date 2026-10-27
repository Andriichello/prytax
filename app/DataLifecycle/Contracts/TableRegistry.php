<?php

namespace PryTax\DataLifecycle\Contracts;

use PryTax\DataLifecycle\Registry\TableSpec;

/**
 * Interface TableRegistry.
 *
 * The tables of a tenant, which the app defines (backups.md §4.2, §10.4): their order is the FK-safe order of an
 * import, and reversed, of a purge. Every table with the tenant's key or a morph pair is either in it or excluded
 * with a reason, which a test checks.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Contracts
 */
interface TableRegistry
{
    /**
     * Get the tables in FK-safe insert order.
     *
     * @return array<int, TableSpec>
     */
    public function tables(): array;

    /**
     * Get the tables, which are left out on purpose, with the reason.
     *
     * @return array<string, string>
     */
    public function excluded(): array;

    /**
     * Get the version of the archives' format, which is bumped for breaking changes of the registry only: a dropped
     * or renamed column, a changed treatment, a removed table (D11).
     *
     * @return int
     */
    public function formatVersion(): int;
}
