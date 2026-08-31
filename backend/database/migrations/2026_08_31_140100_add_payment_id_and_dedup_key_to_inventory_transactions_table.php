<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Database Design 2.6 — "Order & Payment State Models" §9, "Ledger
 * Idempotency — dedup_key". Replaces the 2.0-era `unique(order_item_id,
 * reason)` key, which cannot support a payment retry's claim/release cycle
 * (a retried attempt legitimately needs a second checkout/release pair for
 * the same order_item_id).
 *
 * A bare `unique(order_item_id, reason, payment_id)` composite was
 * evaluated and rejected during design review: `payment_id` is null for
 * `restock`/`adjustment`, and MySQL treats every NULL in a unique index as
 * distinct from every other NULL — so that composite key's protection for
 * `restock`/`adjustment` would depend entirely on `order_item_id` also
 * happening to be null for those rows, which is true today only by
 * convention, not by anything the constraint itself enforces. `dedup_key`
 * instead computes a value purely as a function of `reason`, making the
 * `restock`/`adjustment` exemption explicit and reason-driven rather than
 * an incidental consequence of which columns are currently null.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->foreignId('payment_id')
                ->nullable()
                ->after('order_item_id')
                ->constrained()
                ->restrictOnDelete();
        });

        // Must be dropped, not kept alongside the new dedup_key unique
        // index below: checkout/release/refund rows all have a non-null
        // order_item_id, so this old constraint would still reject a
        // second checkout for a retried payment attempt before dedup_key
        // is ever reached, defeating the point of this migration.
        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->dropUnique(['order_item_id', 'reason']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE inventory_transactions
            ADD COLUMN dedup_key VARCHAR(64)
            GENERATED ALWAYS AS (
                CASE
                    WHEN reason IN ('checkout', 'release', 'refund')
                        THEN CONCAT(order_item_id, ':', reason, ':', payment_id)
                    ELSE NULL
                END
            ) STORED
        SQL);

        // Defense-in-depth: MySQL's CONCAT() returns NULL if any argument
        // is NULL, so a bug that inserted a checkout/release/refund row
        // with a null order_item_id/payment_id would otherwise silently
        // produce a null dedup_key and escape the unique index entirely.
        // This CHECK turns that into a hard INSERT failure instead.
        DB::statement(<<<'SQL'
            ALTER TABLE inventory_transactions
            ADD CONSTRAINT inventory_transactions_payment_linked_reason_requires_ids
            CHECK (
                reason NOT IN ('checkout', 'release', 'refund')
                OR (order_item_id IS NOT NULL AND payment_id IS NOT NULL)
            )
        SQL);

        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->unique('dedup_key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->dropUnique(['dedup_key']);
        });

        DB::statement('ALTER TABLE inventory_transactions DROP CONSTRAINT inventory_transactions_payment_linked_reason_requires_ids');

        DB::statement('ALTER TABLE inventory_transactions DROP COLUMN dedup_key');

        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->dropForeign(['payment_id']);
            $table->dropColumn('payment_id');
        });

        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->unique(['order_item_id', 'reason']);
        });
    }
};
