<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates only the shape of checkout input — item ids/quantities and
 * shipping-address fields are plain format checks here; whether a variant
 * actually exists, belongs to this store, and is active is authoritative
 * business validation the controller performs against MySQL, not
 * duplicated here (same discipline as InventoryAdjustRequest, which
 * validates request shape while the service stays the source of truth).
 *
 * The Idempotency-Key header is merged into the validated input under
 * `idempotency_key` so it's validated and retrieved the same way as any
 * other required field — required per the approved STEP 3B design; a
 * missing key is a client error, never silently defaulted or generated
 * server-side. It's trimmed before validation so a whitespace-only header
 * (which Laravel's `required` rule alone would treat as "present") is
 * correctly rejected, and so the trimmed value — not the raw header — is
 * what the controller, Stripe, and orders.idempotency_key all agree on.
 *
 * Duplicate `product_variant_id` entries in `items` are merged into a
 * single line with a summed quantity, also before validation runs, so
 * `[{123,2},{123,3}]` and `[{123,5}]` are indistinguishable from this
 * point on — to every downstream step (quantity validation, pricing, the
 * idempotency payload hash, and CheckoutOrderCreationService's own line
 * items) they are the same normalized cart, not two different shapes that
 * happen to produce the same total.
 */
class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $key = $this->header('Idempotency-Key');

        $this->merge([
            'idempotency_key' => is_string($key) ? trim($key) : $key,
        ]);

        if (is_array($this->input('items'))) {
            $this->merge([
                'items' => $this->mergeDuplicateItems($this->input('items')),
            ]);
        }
    }

    /**
     * Groups items by product_variant_id, summing quantity for repeated
     * ids, while preserving the order each id first appeared in. Never
     * throws on malformed input — a shape this method doesn't recognize
     * (missing keys, a non-numeric id/quantity) is passed through
     * unchanged so the normal `required`/`integer` rules below reject it
     * with their standard validation message, since this runs before
     * those rules ever get a chance to.
     *
     * @param  array<int, mixed>  $items
     * @return array<int, mixed>
     */
    private function mergeDuplicateItems(array $items): array
    {
        $merged = [];
        $indexByVariantId = [];

        foreach ($items as $item) {
            if (
                ! is_array($item)
                || ! array_key_exists('product_variant_id', $item)
                || ! array_key_exists('quantity', $item)
                || ! is_numeric($item['product_variant_id'])
                || ! is_numeric($item['quantity'])
            ) {
                $merged[] = $item;

                continue;
            }

            $variantId = (int) $item['product_variant_id'];

            if (! array_key_exists($variantId, $indexByVariantId)) {
                $indexByVariantId[$variantId] = count($merged);
                $merged[] = ['product_variant_id' => $variantId, 'quantity' => 0];
            }

            $merged[$indexByVariantId[$variantId]]['quantity'] += (int) $item['quantity'];
        }

        return $merged;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'max:255'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.product_variant_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],

            'shipping_address' => ['required', 'array'],
            'shipping_address.recipient_name' => ['required', 'string', 'max:255'],
            'shipping_address.line1' => ['required', 'string', 'max:255'],
            'shipping_address.line2' => ['nullable', 'string', 'max:255'],
            'shipping_address.city' => ['required', 'string', 'max:100'],
            'shipping_address.state' => ['required', 'string', 'max:100'],
            'shipping_address.postal_code' => ['required', 'string', 'max:20'],
            'shipping_address.country' => ['required', 'string', 'size:2'],
            'shipping_address.phone' => ['nullable', 'string', 'max:50'],
        ];
    }
}
