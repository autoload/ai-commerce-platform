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
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')
                ->constrained()
                ->restrictOnDelete();
            $table->foreignId('store_id')
                ->constrained()
                ->restrictOnDelete();
            $table->foreignId('order_id')
                ->constrained()
                ->restrictOnDelete();
            $table->foreignId('payment_id')
                ->constrained()
                ->restrictOnDelete();
            $table->foreignId('initiated_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('stripe_refund_id');
            $table->decimal('amount', 10, 2);
            $table->string('reason')->nullable();
            $table->string('status')->default('pending');
            $table->timestamps();

            $table->unique('stripe_refund_id');
            $table->index(['order_id', 'status']);
            $table->index(['store_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
