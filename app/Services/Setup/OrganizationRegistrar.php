<?php

namespace PryTax\Services\Setup;

use Core\Models\Organization;
use Core\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Class OrganizationRegistrar.
 *
 * A new organization with its owner (APP-BUILDS-PLAN.md): registered from the sign-in page, or the first one of an
 * installation. The organization is active and the owner verified, with a password of their own, so they sign in
 * right away; then they invite the others.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Services\Setup
 */
class OrganizationRegistrar
{
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
     */
    public function register(string $organization, string $name, string $email, string $password, string $locale = 'uk'): User
    {
        return DB::transaction(function () use ($organization, $name, $email, $password, $locale) {
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

            return $user->assignRole('owner');
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
