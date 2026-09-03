<?php

use App\Http\Controllers\Catalog\ProductController as CatalogProductController;
use App\Http\Controllers\Customer\CheckoutController;
use App\Http\Controllers\Customer\CustomerAuthController;
use App\Http\Controllers\Customer\RetryPaymentController;
use App\Http\Controllers\Merchant\InventoryController;
use App\Http\Controllers\Merchant\MerchantAuthController;
use App\Http\Controllers\Merchant\OrderController;
use App\Http\Controllers\Merchant\ProductController;
use App\Http\Controllers\Merchant\StoreController;
use App\Http\Controllers\Platform\PlatformAuthController;
use App\Http\Controllers\Webhooks\StripeWebhookController;
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

Route::prefix('customers')->name('customers.')->group(function () {
    Route::post('/auth/register', [CustomerAuthController::class, 'register'])->name('auth.register');
    Route::post('/auth/login', [CustomerAuthController::class, 'login'])->name('auth.login');

    Route::middleware(['auth:customer', 'tenant.customer'])->group(function () {
        Route::post('/auth/logout', [CustomerAuthController::class, 'logout'])->name('auth.logout');
        Route::get('/auth/me', [CustomerAuthController::class, 'me'])->name('auth.me');
    });
});

// STEP 3B: checkout orchestration. Its own top-level resource, not nested
// under /customers, matching system-architecture.md §10's API listing.
Route::post('/checkout', [CheckoutController::class, 'store'])
    ->middleware(['auth:customer', 'tenant.customer'])
    ->name('checkout.store');

// Phase 3 STEP 3D: payment retry. {order} is resolved and scoped to the
// authenticated customer inside RetryPaymentController itself (not via
// implicit route-model binding), matching every other resource-resolution
// convention in this codebase.
Route::post('/orders/{order}/payment-retry', [RetryPaymentController::class, 'store'])
    ->middleware(['auth:customer', 'tenant.customer'])
    ->name('orders.paymentRetry');

// STEP 3C: Stripe webhook. Deliberately unauthenticated and untenanted —
// Stripe cannot authenticate via any of this app's guards, and Stripe's
// signature verification (inside the controller) is the entire trust
// boundary. No auth:*/tenant.* middleware, matching system-architecture.md
// §10 ("the Stripe webhook route is the one deliberate exception to normal
// auth — excluded from Sanctum/CSRF, verified by Stripe's signature
// instead"). API routes carry no CSRF middleware in this Laravel version
// regardless, so nothing extra needs excluding here.
Route::post('/webhooks/stripe', [StripeWebhookController::class, 'handle'])->name('webhooks.stripe');

// Public, unauthenticated catalog browsing — no guard, distinct from the
// merchant-only /api/stores/{store}/products above (same {store} scoping
// discipline, different audience/URI namespace to avoid colliding with it).
Route::prefix('shop')->name('shop.')->group(function () {
    Route::get('/stores/{store}/products', [CatalogProductController::class, 'index'])->name('products.index');
    Route::get('/stores/{store}/products/{product}', [CatalogProductController::class, 'show'])->name('products.show');
});
