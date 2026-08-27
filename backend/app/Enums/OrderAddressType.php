<?php

namespace App\Enums;

/**
 * Single value for MVP; modeled as an enum so a future `billing` type
 * is a data change, not a schema migration, per Database Design 2.5.
 */
enum OrderAddressType: string
{
    case Shipping = 'shipping';
}
