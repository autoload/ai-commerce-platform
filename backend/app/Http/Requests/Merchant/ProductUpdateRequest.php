<?php

namespace App\Http\Requests\Merchant;

use App\Models\ProductVariant;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Same field set as ProductCreateRequest, all optional (`sometimes`) — no
 * `slug` (not editable in this MVP, same reasoning as StoreUpdateRequest),
 * no organization_id/store_id, no option matrix fields.
 */
class ProductUpdateRequest extends FormRequest
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
        $variantId = ProductVariant::where('product_id', $this->route('product'))->value('id');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'category_id' => ['sometimes', 'nullable', 'integer', Rule::exists('categories', 'id')->where(
                fn ($query) => $query->where('store_id', $storeId)
            )],
            'status' => ['sometimes', 'required', Rule::in(['draft', 'active', 'archived'])],
            'sku' => ['sometimes', 'required', 'string', 'max:100', Rule::unique('product_variants', 'sku')
                ->where(fn ($query) => $query->where('store_id', $storeId))
                ->ignore($variantId)],
            'price' => ['sometimes', 'required', 'numeric', 'min:0'],
            'compare_at_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ];
    }
}
