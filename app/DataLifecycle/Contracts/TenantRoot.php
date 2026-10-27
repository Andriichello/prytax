<?php

namespace PryTax\DataLifecycle\Contracts;

/**
 * Interface TenantRoot.
 *
 * The record, whose data an archive holds (backups.md §10.4): PryTax's profile, another app's organization. It names
 * the root table, the foreign key of the tenant's tables, and how an archive describes and names a tenant.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Contracts
 */
interface TenantRoot
{
    /**
     * Get the root table (`profiles`).
     *
     * @return string
     */
    public function table(): string;

    /**
     * Get the column, with which the tenant's tables reference the root (`profile_id`).
     *
     * @return string
     */
    public function foreignKey(): string;

    /**
     * Get the column of the root, which names its organization (`organization_id`).
     *
     * @return string
     */
    public function organizationColumn(): string;

    /**
     * Get the key, under which the manifest describes the tenant (`profile`).
     *
     * @return string
     */
    public function manifestKey(): string;

    /**
     * Describe the tenant for the manifest, from its exported (decrypted) row; never more than identifies it.
     *
     * @param array<string, string|null> $row
     *
     * @return array<string, mixed>
     */
    public function describe(array $row): array;

    /**
     * Get the folder of the tenant in an organization's bundle, which has no personal data, such as a tax id.
     *
     * @param array<string, string|null> $row
     *
     * @return string
     */
    public function folderName(array $row): string;

    /**
     * Tell why the archive's tenant can't be imported into the organization, or null, when it can.
     *
     * @param array<string, string|null> $row The exported (decrypted) row.
     * @param int $organizationId
     *
     * @return string|null
     */
    public function importConflict(array $row, int $organizationId): ?string;
}
