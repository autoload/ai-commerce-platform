<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * `type` is intentionally a plain string, not a native enum cast — it is
 * Stripe's own externally-controlled event-type vocabulary
 * (payment_intent.succeeded, etc.), and a closed local enum would break
 * the moment Stripe adds an event type this app doesn't yet recognize.
 *
 * No relationships: standalone by design, resolves to payments/refunds
 * via Stripe's own IDs during processing, not via foreign key.
 */
#[Fillable([])]
class StripeWebhookEvent extends Model
{
    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'processed_at' => 'datetime',
            'payload' => 'array',
        ];
    }
}
