<?php

namespace App\Models;

use App\Enums\OrderAddressType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([])]
class OrderAddress extends Model
{
    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'type' => OrderAddressType::class,
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
