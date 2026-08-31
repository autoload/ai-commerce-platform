<?php

namespace App\Enums;

enum OrganizationRole: string
{
    case Owner = 'owner';
    case StoreAdmin = 'store_admin';
    case Staff = 'staff';
}
