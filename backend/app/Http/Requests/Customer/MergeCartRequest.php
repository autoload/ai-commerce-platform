<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/cart/merge — the guest localStorage cart's contents, sent once
 * right after a successful login/registration. `items` may legitimately be
 * empty (the frontend is expected to skip calling this endpoint at all
 * when the guest cart is empty, per the approved Phase 8B revision design,
 * but the backend stays robust to an empty array regardless rather than
 * requiring the caller to know that). No dedup step is needed for repeated
 * product_variant_id entries within the payload the way CheckoutRequest
 * needs one for checkout — CartService::mergeGuestCart() sums via
 * atomic HINCRBY per line, so N entries for the same variant already sum
 * correctly regardless of how many separate lines they arrived as.
 */
class MergeCartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'items' => ['sometimes', 'array'],
            'items.*.product_variant_id' => ['required', 'integer', 'min:1'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ];
    }
}
