<?php

namespace App\Http\Resources\Platform;

use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Platform-Admin-facing representation of an Organization. Deliberately
 * exposes only the organization's own row fields (approved Phase 9A
 * design) — no store count, owner info, or other cross-entity/aggregate
 * data; that's explicitly out of scope for Phase 9A.
 *
 * @mixin Organization
 */
class OrganizationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'status' => $this->status->value,
            'status_reason' => $this->status_reason,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'approved_by_platform_admin_id' => $this->approved_by_platform_admin_id,
            'rejected_at' => $this->rejected_at?->toIso8601String(),
            'rejected_by_platform_admin_id' => $this->rejected_by_platform_admin_id,
            'suspended_at' => $this->suspended_at?->toIso8601String(),
            'suspended_by_platform_admin_id' => $this->suspended_by_platform_admin_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
