<?php

namespace PryTax\Services\Setup;

use Core\Models\Organization;
use Core\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PryTax\Backups\ProfileArchives;
use PryTax\Exceptions\ArchiveRefusedException;

/**
 * Class OrganizationRegistrar.
 *
 * A new organization with its owner (builds.md §1.3): registered from the sign-in page, or the first one of an
 * installation. The organization is active and the owner verified, with a password of their own, so they sign in
 * right away; then they invite the others. It may start with the ФОПs of backup archives, which are imported into it
 * as new ФОПs: all of them, or, when one can't be, nothing is created.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Services\Setup
 */
class OrganizationRegistrar
{
    /**
     * OrganizationRegistrar constructor.
     *
     * @param ProfileArchives $archives
     */
    public function __construct(protected ProfileArchives $archives) {}

    /**
     * Create the organization and its owner, with the ФОПs of the archives, if any.
     *
     * @param string $organization The organization's name
     * @param string $name
     * @param string $email
     * @param string $password
     * @param string $locale
     * @param list<array{path: string, password?: string|null, name?: string}> $archives Backup archives of ФОПs.
     *
     * @return User
     * @throws ArchiveRefusedException
     */
    public function register(string $organization, string $name, string $email, string $password, string $locale = 'uk', array $archives = []): User
    {
        return DB::transaction(function () use ($organization, $name, $email, $password, $locale, $archives) {
            $record = Organization::query()->create(['slug' => $this->slug($organization), 'name' => $organization]);
            $record->forceFill(['activated_at' => now()])->save();

            $user = new User([
                'name' => $name,
                'email' => Str::lower($email),
                'password' => $password,
                'timezone' => config('app.timezone'),
                'locale' => $locale,
            ]);
            $user->forceFill([
                'organization_id' => $record->id,
                'email_verified_at' => now(),
                'password_updated_at' => now(),
            ])->save();

            $user->assignRole('owner');
            $this->archives->import($archives, $record->id, $user);

            return $user;
        });
    }

    /**
     * Get a slug of the name, which no organization has, deleted ones included: `praktyka`, then `praktyka-2`.
     *
     * @param string $name
     *
     * @return string
     */
    protected function slug(string $name): string
    {
        $base = Str::slug($name) ?: 'organization';
        $slug = $base;

        for ($suffix = 2; Organization::query()->withTrashed()->where('slug', $slug)->exists(); $suffix++) {
            $slug = "$base-$suffix";
        }

        return $slug;
    }
}
