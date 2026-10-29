<?php

namespace PryTax\Backups;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PryTax\DataLifecycle\Contracts\TenantRoot;
use PryTax\Models\Profile;

/**
 * Class ProfileTenantRoot.
 *
 * PryTax's tenant of the archives is the profile (ФОП): its tables reference it by `profile_id`, an archive holds one
 * profile, and a bundle names its folder `slug(full_name_en)_p{id}`, without the tax id (backups.md §2.4).
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Backups
 */
final class ProfileTenantRoot implements TenantRoot
{
    /**
     * Get the root table.
     *
     * @return string
     */
    public function table(): string
    {
        return 'profiles';
    }

    /**
     * Get the column, with which the profile's tables reference it.
     *
     * @return string
     */
    public function foreignKey(): string
    {
        return 'profile_id';
    }

    /**
     * Get the column of the profile's organization.
     *
     * @return string
     */
    public function organizationColumn(): string
    {
        return 'organization_id';
    }

    /**
     * Get the key, under which the manifest describes the profile.
     *
     * @return string
     */
    public function manifestKey(): string
    {
        return 'profile';
    }

    /**
     * Describe the profile: its id, name and the last four digits of its tax id.
     *
     * @param array<string, string|null> $row
     *
     * @return array{id: int, full_name: string|null, tax_id_last4: string}
     */
    public function describe(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'full_name' => $row['full_name'],
            'tax_id_last4' => substr((string) $row['tax_id'], -4),
        ];
    }

    /**
     * Get the profile's folder in a bundle: `kovalenko-olena_p42`.
     *
     * @param array<string, string|null> $row
     *
     * @return string
     */
    public function folderName(array $row): string
    {
        $slug = Str::slug((string) ($row['full_name_en'] ?: $row['full_name']));

        return ($slug === '' ? 'profile' : $slug) . '_p' . $row['id'];
    }

    /**
     * Tell why the profile can't be imported into the organization: a profile with its tax id is there, deleted ones
     * too, as `UNIQUE(organization_id, tax_id_hash)` counts them (backups.md §5.1).
     *
     * @param array<string, string|null> $row
     * @param int $organizationId
     *
     * @return string|null
     */
    public function importConflict(array $row, int $organizationId): ?string
    {
        $existing = DB::table('profiles')
            ->where('organization_id', $organizationId)
            ->where('tax_id_hash', Profile::hashTaxId((string) $row['tax_id']))
            ->first(['id', 'deleted_at']);

        if ($existing === null) {
            return null;
        }

        return $existing->deleted_at
            ? __('The organization has this ФОП already, deleted (profile :id): purge it first, or restore into another organization.', ['id' => $existing->id])
            : __('The organization has this ФОП already (profile :id): restore it into another organization.', ['id' => $existing->id]);
    }
}
