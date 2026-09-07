<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cart Redis Connection
    |--------------------------------------------------------------------------
    |
    | The Redis connection (see config/database.php's "redis" array) that
    | App\Services\CartService reads/writes the authenticated-customer cart
    | through. Deliberately its own connection/database index — never the
    | queue's "default" connection or the "cache" connection — per
    | CLAUDE.md's "four distinct Redis roles" framing and the explicit
    | Phase 8B revision instruction not to couple cart storage to the queue
    | Redis role.
    |
    */

    'redis_connection' => env('CART_REDIS_CONNECTION', 'cart'),

    /*
    |--------------------------------------------------------------------------
    | Cart TTL (seconds)
    |--------------------------------------------------------------------------
    |
    | A sliding TTL applied to an authenticated customer's Redis cart hash
    | on every mutation (add/set/remove/merge) — refreshed, not fixed at
    | first write, so an actively-used cart never expires mid-session.
    |
    | No prior project convention fixes this value: system-architecture.md
    | §7 explicitly documents it as "an implementation detail, not fixed
    | here," and neither CLAUDE.md nor database-design.md name a duration.
    | 30 days is a provisional default chosen for this implementation (long
    | enough that a returning customer's cart survives a normal browsing
    | gap, short enough that Redis doesn't accumulate abandoned carts
    | indefinitely) — not a re-derivation of an existing rule. Revisit if a
    | real usage pattern suggests otherwise.
    |
    */

    'ttl_seconds' => (int) env('CART_TTL_SECONDS', 60 * 60 * 24 * 30),

];
