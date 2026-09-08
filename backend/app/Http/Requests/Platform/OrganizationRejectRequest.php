<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `reason` is required for Reject (approved decision) — recorded into
 * organizations.status_reason. Business-rule authorization (guard-only,
 * no Policy — approved decision) happens in the controller, not here;
 * this class validates shape only, matching every other FormRequest in
 * this codebase.
 */
class OrganizationRejectRequest extends FormRequest
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
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
