<?php

namespace PryTax\Http\Controllers;

use Core\Http\Controllers\Auth\AuthController;
use Core\Http\Resources\MeResource;
use Core\Http\Responses\ApiResponse;
use Core\Models\User;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\TransientToken;
use PryTax\Http\Requests\Auth\RegisterRequest;
use PryTax\Services\Setup\InstallationSetup;
use PryTax\Services\Setup\OrganizationRegistrar;
use PryTax\Services\Setup\SingleOrganization;

/**
 * Class RegistrationController.
 *
 * Registering a new organization from the sign-in page (builds.md §1.3), when `prytax.registration` allows it:
 * `GET registration` tells the SPA whether to offer it, and whether the installation has no user yet (the desktop
 * app's first launch, which opens the form at once); `POST registration` creates the organization and its owner,
 * and signs the SPA in, as api-core's login does. One person's installation (builds.md §1.4) registers its only
 * organization with a password alone, and tells the sign-in page when the password alone signs in.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Controllers
 */
class RegistrationController extends Controller
{
    /**
     * RegistrationController constructor.
     *
     * @param OrganizationRegistrar $registrar
     * @param InstallationSetup $setup
     * @param SingleOrganization $single
     */
    public function __construct(
        protected OrganizationRegistrar $registrar,
        protected InstallationSetup $setup,
        protected SingleOrganization $single,
    ) {}

    /**
     * Register the routes of the controller, limited as api-core's sign-in.
     *
     * @return void
     */
    public static function routes(): void
    {
        Route::middleware('throttle:' . AuthController::RATE_LIMITER)->group(function () {
            Route::get('registration', [static::class, 'status'])->name('registration.status');
            Route::post('registration', [static::class, 'register'])->name('registration.register');
        });
    }

    /**
     * Tell whether a new organization may be registered, and whether it would be the installation's first.
     *
     * @return ApiResponse
     */
    public function status(): ApiResponse
    {
        $enabled = $this->single->allowsRegistration();

        return ApiResponse::ok(['data' => [
            'enabled' => $enabled,
            'first_owner' => $enabled && $this->setup->isNeeded(),
            'single_organization' => $this->single->isOn(),
            'password_only' => $this->single->account() !== null,
        ]]);
    }

    /**
     * Register the organization and its owner, and sign the SPA in to the session.
     *
     * @param RegisterRequest $request
     *
     * @return ApiResponse
     */
    public function register(RegisterRequest $request): ApiResponse
    {
        abort_unless($this->single->allowsRegistration(), 404);

        $locale = $request->string('locale', app()->getLocale())->value();
        $user = $this->single->isOn()
            ? $this->single->register($request->string('password')->value(), $locale)
            : $this->registrar->register(
                $request->string('organization')->value(),
                $request->string('name')->value(),
                $request->string('email')->value(),
                $request->string('password')->value(),
                $locale,
            );

        if ($request->hasSession()) {
            $this->loginToSession($request, $user);
        }

        return ApiResponse::created(['data' => ['user' => new MeResource($user->refresh()->withAccessToken(new TransientToken))]]);
    }

    /**
     * Sign in to the session, remembered in a cookie, when asked, as api-core's login does.
     *
     * @param Request $request
     * @param User $user
     *
     * @return void
     */
    protected function loginToSession(Request $request, User $user): void
    {
        /** @var SessionGuard $guard */
        $guard = Auth::guard(Arr::first(Arr::wrap(config('sanctum.guard', 'web'))));
        $guard->login($user, $request->boolean('remember'));
        $user->forceFill(['last_login_at' => now()])->save();
    }
}
