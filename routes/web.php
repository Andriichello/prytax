<?php

use Illuminate\Support\Facades\Route;
use PryTax\DataLifecycle\Backup\Http\Controllers\ArchiveController;

ArchiveController::routes();

/*
 * The SPA answers every other path (its router shows the page, or its own "not found"), so links the API sends, such
 * as `/invoices/{id}` and `/reset-password`, open the app. The API, Sanctum's CSRF cookie, the served `local` disk
 * (`/storage`, whose route comes after this one) and the health check keep their own routes and errors.
 */
Route::view('/{path?}', 'app')
    ->where('path', '(?!api(?:/|$)|sanctum/|storage/|up$).*')
    ->name('app');
