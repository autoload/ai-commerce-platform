<?php

namespace App\Http\Requests\Merchant;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Deliberately no organization_id/store_id field — the store always comes
 * from the verified route/TenantContext, never client input. `sku`/`price`/
 * `compare_at_price` describe this MVP's single default ProductVariant
 * (created atomically alongside the Product by the controller) — no option
 * matrix or variant-relationship fields are accepted.
 */
class ProductCreateRequest extends FormRequest
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
        $storeId = app(TenantContext::class)->store->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where(
                fn ($query) => $query->where('store_id', $storeId)
            )],
            'status' => ['nullable', Rule::in(['draft', 'active', 'archived'])],
            'sku' => ['required', 'string', 'max:100', Rule::unique('product_variants', 'sku')->where(
                fn ($query) => $query->where('store_id', $storeId)
            )],
            'price' => ['required', 'numeric', 'min:0'],
            'compare_at_price' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
