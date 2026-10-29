<?php

namespace PryTax\Services\Setup;

use Core\Models\Organization;
use Core\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PryTax\Exceptions\InstallationAlreadySetUpException;

/**
 * Class InstallationSetup.
 *
 * The first owner of a fresh installation (APP-BUILDS-PLAN.md): the API has no sign-up, as users come from an owner's
 * invitations, so a new server or desktop install gets its organization and owner here, verified and with a password
 * of their own, so they can sign in right away. Once any user exists, it refuses.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Services\Setup
 */
class InstallationSetup
{
    /**
     * Determine whether the installation still needs its first owner: no user exists, deleted ones included.
     *
     * @return bool
     */
    public function isNeeded(): bool
    {
        return !User::query()->withTrashed()->exists();
    }

    /**
     * Create the organization and its owner.
     *
     * @param string $organization The organization's name
     * @param string $name
     * @param string $email
     * @param string $password
     * @param string $locale
     *
     * @return User
     * @throws InstallationAlreadySetUpException
     */
    public function createOwner(string $organization, string $name, string $email, string $password, string $locale = 'uk'): User
    {
        return DB::transaction(function () use ($organization, $name, $email, $password, $locale) {
            if (!$this->isNeeded()) {
                throw new InstallationAlreadySetUpException('The installation already has users; invite more from the app.');
            }

            $record = Organization::query()->create(['slug' => Str::slug($organization) ?: 'organization', 'name' => $organization]);
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

            return $user->assignRole('owner');
        });
    }
}
