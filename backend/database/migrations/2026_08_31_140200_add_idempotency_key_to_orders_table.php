<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Database Design 2.6 — "Order & Payment State Models" §11. Durable,
 * DB-backed checkout-submission idempotency — Redis alone is a
 * performance optimization, never the correctness mechanism (Redis is
 * documented elsewhere in this project as non-durable, best-effort,
 * cache-tier storage for every one of its roles).
 *
 * Scoped `unique(customer_id, idempotency_key)`, not globally unique: the
 * key's meaning is "this customer's Nth checkout attempt," so scoping to
 * the customer is the semantically correct boundary (a customer already
 * belongs to exactly one store, so this also implicitly scopes by store).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('idempotency_key')->nullable()->after('customer_id');
            $table->string('idempotency_key_payload_hash', 64)->nullable()->after('idempotency_key');

            $table->unique(['customer_id', 'idempotency_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // None of orders' pre-existing indexes have customer_id as a
        // leftmost column, so MySQL adopts the composite unique index
        // below as the sole index satisfying the customer_id foreign key
        // once it's added — and refuses to drop it while it's the only
        // one covering that FK. Re-establishing a plain index on
        // customer_id first gives the FK somewhere else to attach to.
        Schema::table('orders', function (Blueprint $table) {
            $table->index('customer_id');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['customer_id', 'idempotency_key']);
            $table->dropColumn(['idempotency_key', 'idempotency_key_payload_hash']);
        });
    }
};
