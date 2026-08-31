<?php

namespace App\Enums;

enum OrganizationRole: string
{
    case Owner = 'owner';
    case StoreAdmin = 'store_admin';
    case Staff = 'staff';

    /** Higher number = more capability. Owner > Store Admin > Staff. */
    public function rank(): int
    {
        return match ($this) {
            self::Owner => 3,
            self::StoreAdmin => 2,
            self::Staff => 1,
        };
    }

    /** True if this role's capability is at least as high as $minimum's. */
    public function atLeast(self $minimum): bool
    {
        return $this->rank() >= $minimum->rank();
    }
}
