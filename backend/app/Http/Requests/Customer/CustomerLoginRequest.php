<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `store_id` is required here for the same reason it's required at
 * registration: customers.email is unique only per store
 * (unique(store_id, email)), not globally, so a bare email lookup would be
 * ambiguous across stores. This is pre-authentication input — a correct
 * password for that specific store's account is still required.
 */
class CustomerLoginRequest extends FormRequest
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
            'store_id' => ['required', 'integer'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ];
    }
}
