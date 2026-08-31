<?php

namespace App\Http\Requests\Merchant;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Deliberately accepts ONLY `status`, and only the four values a merchant
 * may ever target directly (`cancelled`/`processing`/`shipped`/`completed`)
 * — never `pending`/`paid`/`refunded`, which are webhook/system-only and
 * are rejected here as a validation error, before OrderStatusUpdateService
 * (and MerchantOrderStatusTransitions' edge-validity check) is ever
 * reached. No `status_reason`/note field in this block (approved decision
 * N2) — status_reason stays untouched for future functionality. Any other
 * field in the request body (total, customer_id, store_id, ...) is simply
 * never read: validated() only ever returns the key declared below.
 */
class OrderStatusUpdateRequest extends FormRequest
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
            'status' => ['required', Rule::in(['cancelled', 'processing', 'shipped', 'completed'])],
        ];
    }
}
