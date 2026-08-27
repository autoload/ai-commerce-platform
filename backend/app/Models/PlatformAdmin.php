<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password'])]
class PlatformAdmin extends Authenticatable
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function approvedOrganizations(): HasMany
    {
        return $this->hasMany(Organization::class, 'approved_by_platform_admin_id');
    }

    public function rejectedOrganizations(): HasMany
    {
        return $this->hasMany(Organization::class, 'rejected_by_platform_admin_id');
    }

    public function suspendedOrganizations(): HasMany
    {
        return $this->hasMany(Organization::class, 'suspended_by_platform_admin_id');
    }
}
