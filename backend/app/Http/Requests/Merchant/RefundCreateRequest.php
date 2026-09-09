<?php

namespace App\Http\Requests\Merchant;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Deliberately no amount field — Phase 9D is full refunds only; the
 * amount is always derived from the succeeded Payment server-side, never
 * client-supplied. idempotency_key is required in the body (not a
 * header), matching CheckoutRequest/RetryPaymentRequest's established
 * convention exactly.
 */
class RefundCreateRequest extends FormRequest
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
            'idempotency_key' => ['required', 'string'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
