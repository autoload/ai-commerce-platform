<?php

namespace App\Enums;

/**
 * Shared by products.status and product_variants.status — the two columns
 * are independent, but use the identical value set per Database Design 2.5.
 */
enum CatalogStatus: string
{
    case Active = 'active';
    case Draft = 'draft';
    case Archived = 'archived';
}
