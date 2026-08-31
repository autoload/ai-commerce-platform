<?php

use App\Http\Controllers\Merchant\InventoryController;
use App\Http\Controllers\Merchant\MerchantAuthController;
use App\Http\Controllers\Merchant\OrderController;
use App\Http\Controllers\Merchant\ProductController;
use App\Http\Controllers\Merchant\StoreController;
use App\Http\Controllers\Platform\PlatformAuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Scoped to the "merchant" sanctum guard (provider: users) so a token
// issued to another identity (e.g. platform_admins) is rejected here.
Route::get('/user', function (Request $request) {
    return $request->user('merchant');
})->middleware('auth:merchant');

Route::prefix('auth')->name('auth.')->group(function () {
    Route::post('/register', [MerchantAuthController::class, 'register'])->name('register');
    Route::post('/login', [MerchantAuthController::class, 'login'])->name('login');

    Route::middleware(['auth:merchant', 'tenant.merchant'])->group(function () {
        Route::post('/logout', [MerchantAuthController::class, 'logout'])->name('logout');
        Route::get('/me', [MerchantAuthController::class, 'me'])->name('me');
    });
});

Route::middleware(['auth:merchant', 'tenant.merchant'])->group(function () {
    Route::get('/stores', [StoreController::class, 'index'])->name('stores.index');
    Route::post('/stores', [StoreController::class, 'store'])->name('stores.store');

    Route::middleware('tenant.merchant.store')->group(function () {
        Route::get('/stores/{store}', [StoreController::class, 'show'])->name('stores.show');
        Route::patch('/stores/{store}', [StoreController::class, 'update'])->name('stores.update');
        Route::delete('/stores/{store}', [StoreController::class, 'destroy'])->name('stores.destroy');

        Route::get('/stores/{store}/products', [ProductController::class, 'index'])->name('products.index');
        Route::post('/stores/{store}/products', [ProductController::class, 'store'])->name('products.store');
        Route::get('/stores/{store}/products/{product}', [ProductController::class, 'show'])->name('products.show');
        Route::patch('/stores/{store}/products/{product}', [ProductController::class, 'update'])->name('products.update');
        Route::delete('/stores/{store}/products/{product}', [ProductController::class, 'destroy'])->name('products.destroy');

        Route::get('/stores/{store}/variants/{variant}/inventory', [InventoryController::class, 'show'])->name('inventory.show');
        Route::post('/stores/{store}/variants/{variant}/inventory/adjust', [InventoryController::class, 'adjust'])->name('inventory.adjust');

        Route::get('/stores/{store}/orders', [OrderController::class, 'index'])->name('orders.index');
        Route::get('/stores/{store}/orders/{order}', [OrderController::class, 'show'])->name('orders.show');
        Route::patch('/stores/{store}/orders/{order}/status', [OrderController::class, 'updateStatus'])->name('orders.updateStatus');
    });
});

Route::prefix('platform')->name('platform.')->group(function () {
    Route::post('/auth/login', [PlatformAuthController::class, 'login'])->name('auth.login');

    Route::middleware('auth:platform_admin')->group(function () {
        Route::post('/auth/logout', [PlatformAuthController::class, 'logout'])->name('auth.logout');
        Route::get('/auth/me', [PlatformAuthController::class, 'me'])->name('auth.me');
    });
});
