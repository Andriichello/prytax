<?php

namespace PryTax\Http\Controllers;

use Core\Http\Controllers\Auth\AuthController;
use Core\Http\Resources\MeResource;
use Core\Http\Responses\ApiResponse;
use Core\Models\User;
use Core\Repositories\UserRepository;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\TransientToken;
use PryTax\Services\Setup\SingleOrganization;

/**
 * Class SingleOrganizationController.
 *
 * Signing into one person's installation (builds.md §1.4), under api-core's sign-in limit: with the password alone,
 * while it has one user (`POST single/login`; the account's suspension and the failed attempts as api-core's login
 * tells them), and setting a new password with the recovery code or the installation's key, which signs in at once
 * (`POST single/password`). Elsewhere, or with more users, they don't exist (404).
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Controllers
 */
class SingleOrganizationController extends Controller
{
    /**
     * SingleOrganizationController constructor.
     *
     * @param SingleOrganization $single
     */
    public function __construct(protected SingleOrganization $single) {}

    /**
     * Register the routes of the controller.
     *
     * @return void
     */
    public static function routes(): void
    {
        Route::middleware('throttle:' . AuthController::RATE_LIMITER)->prefix('single')->name('single.')->group(function () {
            Route::post('login', [static::class, 'login'])->name('login');
            Route::post('password', [static::class, 'password'])->name('password');
        });
    }

    /**
     * Sign into the account with its password alone.
     *
     * @param Request $request
     *
     * @return ApiResponse
     */
    public function login(Request $request): ApiResponse
    {
        $account = $this->single->account() ?? abort(404);
        $request->validate(['password' => ['required', 'string'], 'remember' => ['sometimes', 'boolean']]);

        $user = app(UserRepository::class)->findByCredentials($account->email, $request->string('password')->value(), null)->first();

        if ($user === null || !$user->canSignIn()) {
            event(new Failed('sanctum', $user, ['email' => $account->email]));

            throw ValidationException::withMessages(['password' => [$user === null ? __('The password isn\'t right.') : __('This account is suspended.')]]);
        }

        return $this->signedIn($request, $user);
    }

    /**
     * Set a new password with the recovery code or the installation's key, and sign in.
     *
     * @param Request $request
     *
     * @return ApiResponse
     */
    public function password(Request $request): ApiResponse
    {
        abort_if($this->single->account() === null, 404);
        $request->validate([
            'secret' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        $user = $this->single->resetPassword($request->string('secret')->value(), $request->string('password')->value());

        return $this->signedIn($request, $user);
    }

    /**
     * Sign the user in to the session (remembered, when asked), as api-core's login does, and answer with them.
     *
     * @param Request $request
     * @param User $user
     *
     * @return ApiResponse
     */
    protected function signedIn(Request $request, User $user): ApiResponse
    {
        $user->forceFill(['last_login_at' => now()])->save();

        if ($request->hasSession()) {
            /** @var SessionGuard $guard */
            $guard = Auth::guard(Arr::first(Arr::wrap(config('sanctum.guard', 'web'))));
            $request->session()->forget([User::IMPERSONATOR_SESSION_KEY, User::IMPERSONATOR_PASSWORD_SESSION_KEY]);
            $guard->login($user, $request->boolean('remember'));
        }

        return ApiResponse::ok(['data' => ['user' => new MeResource($user->refresh()->withAccessToken(new TransientToken))]]);
    }
}
