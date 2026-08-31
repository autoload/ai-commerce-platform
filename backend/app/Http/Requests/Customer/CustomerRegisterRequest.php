<?php

namespace App\Http\Requests\Customer;

use App\Enums\StoreStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Registration is store-scoped: `store_id` is client-supplied here
 * deliberately — there is no storefront/subdomain resolution yet to infer
 * it from, and this is pre-authentication input (there is no tenant
 * context to bypass; the customer still must control the account they end
 * up creating). Once authenticated, org/store are never re-read from
 * client input again — see CustomerContext/ResolveCustomerContext.
 *
 * `customers.email` is unique only per store (unique(store_id, email)),
 * unlike users.email/platform_admins.email — the uniqueness check below is
 * deliberately scoped to the submitted store_id, not global.
 */
class CustomerRegisterRequest extends FormRequest
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
        $storeId = $this->input('store_id');

        return [
            'store_id' => ['required', 'integer', Rule::exists('stores', 'id')->where(
                fn ($query) => $query->where('status', StoreStatus::Active->value)
            )],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('customers', 'email')->where(
                fn ($query) => $query->where('store_id', $storeId)
            )],
            'phone' => ['nullable', 'string', 'max:50'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ];
    }
}
