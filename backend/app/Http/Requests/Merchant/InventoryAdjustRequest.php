<?php

namespace App\Http\Requests\Merchant;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Merchant-driven inventory adjustments are restricted to `restock` and
 * `adjustment` — never `sale`/`refund`, which are system-driven (future
 * checkout/refund flows) and never originate from this request. The
 * underlying InventoryAdjustmentService itself is not restricted this way;
 * this is where that restriction belongs, one layer up.
 */
class InventoryAdjustRequest extends FormRequest
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
            'delta' => ['required', 'integer', 'not_in:0'],
            'reason' => ['required', Rule::in(['restock', 'adjustment'])],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
