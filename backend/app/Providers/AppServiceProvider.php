<?php

namespace App\Providers;

use App\Models\ProductVariant;
use App\Policies\AnalyticsPolicy;
use App\Policies\InventoryPolicy;
use App\Services\StripeApiPaymentIntentGateway;
use App\Services\StripeApiRefundGateway;
use App\Services\StripePaymentIntentGateway;
use App\Services\StripeRefundGateway;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Stripe\StripeClient;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(StripeClient::class, fn () => new StripeClient(config('services.stripe.secret')));

        $this->app->bind(StripePaymentIntentGateway::class, StripeApiPaymentIntentGateway::class);
        $this->app->bind(StripeRefundGateway::class, StripeApiRefundGateway::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Every other Policy (StorePolicy, ProductPolicy, OrganizationPolicy)
        // is found by Laravel's naming-convention auto-discovery
        // (App\Models\X -> App\Policies\XPolicy). InventoryPolicy is the
        // exception: it authorizes against ProductVariant (inventory itself
        // carries no organization_id/store_id to scope on), so it can't be
        // named to match that convention — it's explicitly registered here
        // instead.
        Gate::policy(ProductVariant::class, InventoryPolicy::class);

        // Analytics has no Eloquent model of its own to authorize
        // against — Gate::define() with an explicit ability name is the
        // established alternative in this codebase whenever a Policy
        // can't be found by convention (see the InventoryPolicy
        // registration above).
        Gate::define('viewAnalytics', [AnalyticsPolicy::class, 'view']);
    }
}
