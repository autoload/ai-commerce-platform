<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * No fillable attributes — quantity_on_hand must never be updated by
 * request handlers directly, only through the single locked
 * inventory-mutation service (not yet built).
 */
#[Fillable([])]
class Inventory extends Model
{
    protected $table = 'inventory';

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
