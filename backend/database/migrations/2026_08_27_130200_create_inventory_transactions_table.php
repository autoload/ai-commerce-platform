<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('inventory_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_variant_id')
                ->constrained()
                ->restrictOnDelete();
            $table->foreignId('order_id')
                ->nullable()
                ->constrained()
                ->restrictOnDelete();
            $table->foreignId('order_item_id')
                ->nullable()
                ->constrained()
                ->restrictOnDelete();
            $table->integer('delta');
            $table->string('reason');
            $table->string('note')->nullable();
            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['order_item_id', 'reason']);
            $table->index(['product_variant_id', 'created_at']);
            $table->index('order_id');
            $table->index('order_item_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_transactions');
    }
};
