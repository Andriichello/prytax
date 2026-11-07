<?php

namespace PryTax\Http\Controllers;

use Core\Http\Resources\MeResource;
use Core\Http\Responses\ApiResponse;
use Illuminate\Support\Facades\Route;
use PryTax\Http\Requests\Auth\UpdateThemeRequest;

/**
 * Class UserThemeController.
 *
 * The theme of the signed-in user's own screens, kept in their `metadata`, which `auth/me` returns, so it follows them
 * to their other devices. api-core's `PATCH auth/me` takes no more than the name, the timezone and the locale.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Http\Controllers
 */
class UserThemeController extends Controller
{
    /**
     * Register the routes of the controller.
     *
     * @return void
     */
    public static function routes(): void
    {
        Route::patch('auth/me/theme', [static::class, 'update'])->name('auth.me.theme');
    }

    /**
     * Update the signed-in user's theme, keeping the rest of their metadata.
     *
     * @param UpdateThemeRequest $request
     *
     * @return ApiResponse
     */
    public function update(UpdateThemeRequest $request): ApiResponse
    {
        $user = $request->user();

        // Not `update()`: the metadata isn't mass assignable, so it would change nothing, without a word
        $user->forceFill(['metadata' => [...($user->metadata ?? []), 'theme' => $request->validated('theme')]])->save();

        return ApiResponse::ok([
            'data' => new MeResource($user),
        ], 'Updated');
    }
}
