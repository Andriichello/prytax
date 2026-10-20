<?php

namespace PryTax\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use PryTax\Helpers\CurrentProfile;
use PryTax\Models\Profile;
use Symfony\Component\HttpFoundation\Response;

/**
 * Class ResolveProfile.
 *
 * Sets CurrentProfile for the request from its `{profile}` route parameter. The profile is looked up within the
 * current organization, which `organization` sets before it, so a profile of another organization is a 404 before
 * any controller code runs. Routes put it, by its alias, after `organization`.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Middleware
 */
class ResolveProfile
{
    /**
     * Alias of the middleware, which AppServiceProvider registers.
     *
     * @var string
     */
    public const string ALIAS = 'profile';

    /**
     * Route parameter of the profile.
     *
     * @var string
     */
    public const string PARAMETER = 'profile';

    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure(Request): Response $next
     *
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        $value = $request->route(static::PARAMETER);

        if ($value !== null) {
            $id = $value instanceof Profile ? $value->getKey() : $value;

            CurrentProfile::set(Profile::query()->index($request->user())->findOrFail($id));
        }

        return $next($request);
    }
}
