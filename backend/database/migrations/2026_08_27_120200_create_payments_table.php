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
        Schema::create('payments', function (Blueprint $table) {
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
            $table->foreignId('payment_method_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            $table->string('stripe_payment_intent_id');
            $table->string('stripe_charge_id')->nullable();
            $table->string('status');
            $table->decimal('amount', 10, 2);
            $table->char('currency', 3);
            $table->string('failure_reason')->nullable();
            $table->timestamps();

            $table->unique('stripe_payment_intent_id');
            $table->index(['order_id', 'status']);
            $table->index(['store_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
