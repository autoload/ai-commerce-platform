<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /api/cart/items/{variant} — sets the exact quantity for one line.
 * {variant} itself comes from the route (constrained to digits at the
 * route-definition level, mirroring every other {id}-shaped route
 * parameter in this codebase); only the new quantity is body input here.
 * Removing a line is a distinct action (DELETE), not quantity: 0.
 */
class UpdateCartItemRequest extends FormRequest
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
            'quantity' => ['required', 'integer', 'min:1'],
        ];
    }
}
