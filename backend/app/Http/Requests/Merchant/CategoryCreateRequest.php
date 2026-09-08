<?php

namespace App\Http\Requests\Merchant;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Deliberately no organization_id/store_id field — the store always comes
 * from the verified route/TenantContext, never client input. No `slug`
 * field either — it is always server-generated (see
 * CategoryController::uniqueSlug()), matching ProductCreateRequest's own
 * "not client-supplied" precedent.
 */
class CategoryCreateRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'integer'],
        ];
    }
}
