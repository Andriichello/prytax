<?php

namespace PryTax\Helpers;

use Closure;
use Core\Helpers\CurrentOrganization;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Support\Facades\Context;
use PryTax\Models\Profile;

/**
 * Class CurrentProfile.
 *
 * The profile (ФОП), in which the current request or job acts, as CurrentOrganization is for organizations. For a
 * request, ResolveProfile sets it from the `{profile}` route parameter, once the profile is found in the request's
 * organization. It is kept in Laravel's Context, so the jobs of the request act in it too. Console commands set it
 * with `run()`. Models with BelongsToProfile see only its records, and none, when it isn't set.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Helpers
 */
class CurrentProfile
{
    /**
     * Key, under which the Context keeps id of the profile.
     *
     * @var string
     */
    public const string CONTEXT_KEY = 'prytax.profile_id';

    /**
     * Get id of the current profile, or null, when it isn't set.
     *
     * @return int|null
     */
    public static function id(): ?int
    {
        $id = Context::get(static::CONTEXT_KEY);

        return $id === null ? null : (int) $id;
    }

    /**
     * Get the current profile, or null, when it isn't set. The profile is looked up without the organization's
     * scope, since it was checked against the organization, when it was made current.
     *
     * @return Profile|null
     */
    public static function get(): ?Profile
    {
        $id = static::id();

        return $id === null
            ? null
            : Profile::query()->withoutGlobalScope(Profile::ORGANIZATION_SCOPE)->find($id);
    }

    /**
     * Determine if the current profile is set.
     *
     * @return bool
     */
    public static function isSet(): bool
    {
        return static::id() !== null;
    }

    /**
     * Set the current profile, or unset it with null.
     *
     * @param Profile|int|null $profile
     *
     * @return void
     */
    public static function set(Profile|int|null $profile): void
    {
        $id = $profile instanceof Profile ? $profile->getKey() : $profile;

        if ($id === null) {
            Context::forget(static::CONTEXT_KEY);

            return;
        }

        Context::add(static::CONTEXT_KEY, (int) $id);
    }

    /**
     * Run the callback in the profile, and in its organization, and then return to the ones, which were current
     * before, even when the callback throws. A pending dispatch, which the callback returns, is dispatched in the
     * profile and returned as null.
     *
     * @template TResult
     *
     * @param Profile $profile
     * @param Closure(): TResult $callback
     *
     * @return TResult|null
     */
    public static function run(Profile $profile, Closure $callback): mixed
    {
        $previous = static::id();

        static::set($profile);

        try {
            return CurrentOrganization::run($profile->organization_id, function () use ($callback) {
                $result = $callback();

                // A pending dispatch dispatches its job, when it's destroyed
                if ($result instanceof PendingDispatch) {
                    unset($result);

                    return null;
                }

                return $result;
            });
        } finally {
            static::set($previous);
        }
    }
}
