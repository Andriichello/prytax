<?php

namespace PryTax\Services\Setup;

use Core\Models\User;
use Illuminate\Support\Facades\DB;
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
     * InstallationSetup constructor.
     *
     * @param OrganizationRegistrar $registrar
     */
    public function __construct(protected OrganizationRegistrar $registrar) {}

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

            return $this->registrar->register($organization, $name, $email, $password, $locale);
        });
    }
}
