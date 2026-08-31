<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Database Design 2.6 — "Order & Payment State Models" §11. Durable
 * idempotency for the (future) retry-payment submission, mirroring
 * orders.idempotency_key's rationale exactly.
 *
 * Scoped `unique(order_id, idempotency_key)`, not globally unique: the
 * key's meaning is "this order's Nth payment attempt." Deliberately does
 * NOT restrict how many Payment rows an order may have overall — the
 * existing multi-attempt-per-order model (a new Payment row per retry) is
 * unaffected; this constraint only prevents the same retry submission
 * from being double-processed.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('idempotency_key')->nullable()->after('stripe_payment_intent_id');

            $table->unique(['order_id', 'idempotency_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['order_id', 'idempotency_key']);
            $table->dropColumn('idempotency_key');
        });
    }
};
