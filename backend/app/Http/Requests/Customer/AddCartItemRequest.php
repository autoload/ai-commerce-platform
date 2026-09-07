<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates request shape only — product_variant_id is a plain integer
 * here, not verified to exist/belong-to-store/be-active. CartService's own
 * hydration step is what authoritatively resolves a variant against MySQL
 * (and silently drops it if it doesn't resolve), the same "validate shape
 * in the Request, business-validate in the service" split CheckoutRequest/
 * InventoryAdjustRequest already establish. Inventory availability is
 * deliberately never checked here — checkout remains the sole authoritative
 * inventory boundary.
 */
class AddCartItemRequest extends FormRequest
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
            'product_variant_id' => ['required', 'integer', 'min:1'],
            'quantity' => ['required', 'integer', 'min:1'],
        ];
    }
}
