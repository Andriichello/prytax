<?php

use Core\Http\Controllers\Auth\AuthController;
use Core\Http\Controllers\NotificationController;
use Core\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(fn () => AuthController::routes());

Route::middleware(['auth:sanctum', 'password.updated'])->group(function () {
    UserController::routes();
    NotificationController::routes();

    Route::middleware('organization')->group(function () {
        // Prytax's own routes, e.g. profiles/{profile}/…
    });
});
