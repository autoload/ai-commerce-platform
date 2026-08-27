<?php

namespace App\Models;

use App\Enums\PaymentMethodType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * No fillable attributes — populated entirely from Stripe API responses
 * by a future PaymentMethodService, never from raw client/form input.
 */
#[Fillable([])]
class PaymentMethod extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'type' => PaymentMethodType::class,
            'is_default' => 'boolean',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
