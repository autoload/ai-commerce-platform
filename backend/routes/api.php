<?php

use App\Http\Controllers\Platform\PlatformAuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Scoped to the "merchant" sanctum guard (provider: users) so a token
// issued to another identity (e.g. platform_admins) is rejected here.
Route::get('/user', function (Request $request) {
    return $request->user('merchant');
})->middleware('auth:merchant');

Route::prefix('platform')->name('platform.')->group(function () {
    Route::post('/auth/login', [PlatformAuthController::class, 'login'])->name('auth.login');

    Route::middleware('auth:platform_admin')->group(function () {
        Route::post('/auth/logout', [PlatformAuthController::class, 'logout'])->name('auth.logout');
        Route::get('/auth/me', [PlatformAuthController::class, 'me'])->name('auth.me');
    });
});
