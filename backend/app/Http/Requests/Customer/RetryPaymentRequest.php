<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The Idempotency-Key header is merged into validated input under
 * `idempotency_key`, trimmed before validation, exactly mirroring
 * CheckoutRequest's convention — required per the approved STEP 3D design;
 * a missing key is a client error, never silently defaulted or generated
 * server-side. No cart/address input: a retry reuses the order's existing,
 * already-committed line items and total — nothing else is accepted here.
 */
class RetryPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $key = $this->header('Idempotency-Key');

        $this->merge([
            'idempotency_key' => is_string($key) ? trim($key) : $key,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'max:255'],
        ];
    }
}
