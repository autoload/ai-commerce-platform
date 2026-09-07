<?php

namespace App\Services;

use App\Enums\CatalogStatus;
use App\Models\ProductVariant;
use App\Support\CustomerContext;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;

/**
 * Phase 8B revision — the single mutation/read path for an authenticated
 * customer's cart. Mirrors this codebase's established "one service owns
 * the storage, the controller never touches it directly" discipline
 * (InventoryAdjustmentService, CheckoutOrderCreationService, etc.), with
 * Redis's own atomic hash commands (HINCRBY/HSET/HDEL) standing in for the
 * `SELECT ... FOR UPDATE` row locking those services use against MySQL —
 * exactly the "Redis Hash per cart, mutated via atomic HINCRBY" primitive
 * system-architecture.md §7 already documented as the intended design.
 *
 * Redis stores the absolute minimum: {product_variant_id: quantity}, one
 * hash per customer, keyed cart:{store_id}:{customer_id} — never display
 * metadata, price, or inventory. Every read (getCart/addItem/etc. all
 * return the hydrated shape) re-derives product/variant/pricing/stock from
 * MySQL, scoped to the customer's own store, matching this project's
 * "never trust a stored snapshot as authoritative" principle already
 * applied to every other Resource in this codebase.
 *
 * Deliberately NOT authoritative for inventory: adding/updating/merging
 * never checks quantity_on_hand and never rejects a quantity for exceeding
 * it. Checkout (CheckoutOrderCreationService, unchanged by this class)
 * remains the sole locked, authoritative inventory boundary — matching the
 * MVP's explicit "no soft-hold/reservation system" decision.
 */
class CartService
{
    private function connection(): Connection
    {
        return Redis::connection(config('cart.redis_connection'));
    }

    private function key(CustomerContext $context): string
    {
        return "cart:{$context->store->id}:{$context->customer->id}";
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, subtotal: string, currency: string}
     */
    public function getCart(CustomerContext $context): array
    {
        $raw = $this->connection()->hgetall($this->key($context));

        return $this->hydrate($context, $raw);
    }

    /**
     * Sums into any existing quantity for this variant — never overwrites.
     * HINCRBY is atomic, so two concurrent adds for the same variant (two
     * browser tabs, a double-click) can never lose an update the way a
     * read-modify-write on a JSON blob could.
     *
     * @return array{items: array<int, array<string, mixed>>, subtotal: string, currency: string}
     */
    public function addItem(CustomerContext $context, int $variantId, int $quantity): array
    {
        $key = $this->key($context);
        $this->connection()->hincrby($key, (string) $variantId, $quantity);
        $this->refreshTtl($key);

        return $this->getCart($context);
    }

    /**
     * Sets the exact quantity for one line (never below 1 — the API layer
     * validates this; removing a line is DELETE, a distinct action).
     *
     * @return array{items: array<int, array<string, mixed>>, subtotal: string, currency: string}
     */
    public function setItemQuantity(CustomerContext $context, int $variantId, int $quantity): array
    {
        $key = $this->key($context);
        $this->connection()->hset($key, (string) $variantId, $quantity);
        $this->refreshTtl($key);

        return $this->getCart($context);
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, subtotal: string, currency: string}
     */
    public function removeItem(CustomerContext $context, int $variantId): array
    {
        $key = $this->key($context);
        $this->connection()->hdel($key, (string) $variantId);
        // Redis itself deletes a hash key once its last field is removed,
        // so refreshing a TTL that may no longer exist is a harmless no-op
        // — kept for consistency with every other mutation here.
        $this->refreshTtl($key);

        return $this->getCart($context);
    }

    public function clearCart(CustomerContext $context): void
    {
        $this->connection()->del($this->key($context));
    }

    /**
     * Login/registration merge: sums each guest line into whatever already
     * exists in the authenticated Redis cart (never overwrites), via the
     * same atomic HINCRBY addItem() uses — so a guest A×2 merging into an
     * existing Redis A×3 always yields A×5, regardless of call order.
     * Malformed lines (non-positive quantity) are skipped rather than
     * aborting the whole merge; a stale/deleted variant id is harmless
     * here too — it simply won't hydrate later (see hydrate()).
     *
     * @param  array<int, array{product_variant_id: int, quantity: int}>  $guestItems
     * @return array{items: array<int, array<string, mixed>>, subtotal: string, currency: string}
     */
    public function mergeGuestCart(CustomerContext $context, array $guestItems): array
    {
        if (empty($guestItems)) {
            return $this->getCart($context);
        }

        $key = $this->key($context);

        foreach ($guestItems as $item) {
            $variantId = (int) ($item['product_variant_id'] ?? 0);
            $quantity = (int) ($item['quantity'] ?? 0);

            if ($variantId < 1 || $quantity < 1) {
                continue;
            }

            $this->connection()->hincrby($key, (string) $variantId, $quantity);
        }

        $this->refreshTtl($key);

        return $this->getCart($context);
    }

    /**
     * Sliding TTL: reissued on every mutation so an actively-used cart
     * never expires mid-session. Never fixed once at first write.
     */
    private function refreshTtl(string $key): void
    {
        $this->connection()->expire($key, (int) config('cart.ttl_seconds'));
    }

    /**
     * Hydrates {variant_id: quantity} into display-ready lines from live
     * MySQL state, scoped strictly to the customer's own store and to
     * currently-Active variants — never trusting the Redis key's own
     * store/customer segments beyond using them to look up the hash.
     * A variant id that no longer resolves (deleted, archived, or somehow
     * belonging to another store) is silently dropped from the response
     * and lazily pruned from Redis, mirroring the "lazily materialized"
     * precedent already used for Inventory rows — one stale line never
     * fails the whole cart read.
     *
     * @param  array<string, string>  $raw
     * @return array{items: array<int, array<string, mixed>>, subtotal: string, currency: string}
     */
    private function hydrate(CustomerContext $context, array $raw): array
    {
        if (empty($raw)) {
            return ['items' => [], 'subtotal' => '0.00', 'currency' => 'usd'];
        }

        $variantIds = array_map('intval', array_keys($raw));

        $variants = ProductVariant::with(['product', 'inventory', 'optionValues.option'])
            ->where('store_id', $context->store->id)
            ->where('status', CatalogStatus::Active)
            ->whereIn('id', $variantIds)
            ->get()
            ->keyBy('id');

        $items = [];
        $subtotal = 0.0;
        $staleFields = [];

        foreach ($raw as $variantIdField => $quantityString) {
            $variantId = (int) $variantIdField;
            $quantity = (int) $quantityString;
            $variant = $variants->get($variantId);

            if (! $variant || $quantity < 1) {
                $staleFields[] = $variantIdField;

                continue;
            }

            $lineTotal = round((float) $variant->price * $quantity, 2);
            $subtotal += $lineTotal;

            $items[] = [
                'product_id' => $variant->product_id,
                'product_variant_id' => $variant->id,
                'product_name' => $variant->product->name,
                'sku' => $variant->sku,
                'price' => (string) $variant->price,
                'compare_at_price' => $variant->compare_at_price !== null ? (string) $variant->compare_at_price : null,
                'in_stock' => (bool) ($variant->inventory?->quantity_on_hand > 0),
                'options' => $variant->optionValues->map(fn ($optionValue) => [
                    'option' => $optionValue->option->name,
                    'value' => $optionValue->value,
                ])->all(),
                'quantity' => $quantity,
                'line_total' => number_format($lineTotal, 2, '.', ''),
            ];
        }

        if (! empty($staleFields)) {
            $this->connection()->hdel($this->key($context), ...$staleFields);
        }

        return [
            'items' => $items,
            'subtotal' => number_format($subtotal, 2, '.', ''),
            'currency' => 'usd',
        ];
    }
}
